<?php
/**
 * Advanced Ads stats provider.
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait STW_Dashboard_Mailing_Stats_Advanced_Ads {
	private function advanced_ads_payload( $start, $end, $page, $page_size ) {
		global $wpdb;
		$impressions_table = $wpdb->get_blog_prefix() . 'advads_impressions';
		$clicks_table = $wpdb->get_blog_prefix() . 'advads_clicks';

		if ( ! $this->table_exists( $impressions_table ) ) {
			return array(
				'metrics'    => array(),
				'timeseries' => array(),
				'breakdown'  => array(),
				'rows'       => array(),
			);
		}

		$has_clicks = $this->table_exists( $clicks_table );
		$start_ts = $this->advanced_ads_timestamp( $start . ' 00:00:00' );
		$end_ts = $this->advanced_ads_timestamp( gmdate( 'Y-m-d 00:00:00', strtotime( $end . ' +1 day' ) ) );
		$rows = $this->advanced_ads_rows_query( $impressions_table, $has_clicks ? $clicks_table : '', $start_ts, $end_ts, $page_size, ( $page - 1 ) * $page_size );
		$rows = array_map( array( $this, 'advanced_ads_row' ), is_array( $rows ) ? $rows : array() );
		$all_rows = array_map(
			array( $this, 'advanced_ads_row' ),
			$this->advanced_ads_rows_query( $impressions_table, $has_clicks ? $clicks_table : '', $start_ts, $end_ts, 500, 0 )
		);
		$totals = $this->advanced_ads_totals( $impressions_table, $has_clicks ? $clicks_table : '', $start_ts, $end_ts );
		$timeseries = $this->advanced_ads_timeseries( $impressions_table, $has_clicks ? $clicks_table : '', $start, $end, $start_ts, $end_ts );
		$breakdown = $this->advanced_ads_breakdown( $rows, (int) $totals['impressions'] );

		return array(
			'metrics'    => array(
				array( 'label' => 'Total ads', 'value' => (float) $this->advanced_ads_count(), 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'Impressions', 'value' => (float) $totals['impressions'], 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'Clicks', 'value' => (float) $totals['clicks'], 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'CTR', 'value' => (float) $this->rate( $totals['clicks'], $totals['impressions'] ), 'previous' => null, 'change' => null, 'format' => 'percent' ),
			),
			'timeseries' => $timeseries,
			'breakdown'  => $breakdown,
			'rows'       => $rows,
			'sections'   => array(
				'bannerPerformance' => $this->advanced_ads_banner_performance( $all_rows, $start, $end ),
			),
		);
	}

	private function advanced_ads_rows_query( $impressions_table, $clicks_table, $start_ts, $end_ts, $limit, $offset ) {
		global $wpdb;
		$has_clicks = '' !== $clicks_table;
		$click_select = $has_clicks
			? "(SELECT COALESCE(SUM(c.count), 0) FROM {$clicks_table} c WHERE c.ad_id = p.ID AND c.timestamp >= %d AND c.timestamp < %d)"
			: '0';
		$query = $wpdb->prepare(
			"SELECT p.ID id, p.post_title name, p.post_status status, p.post_modified_gmt last_updated,
				COALESCE(SUM(i.count), 0) impressions,
				{$click_select} clicks
			FROM {$wpdb->posts} p
			LEFT JOIN {$impressions_table} i ON i.ad_id = p.ID AND i.timestamp >= %d AND i.timestamp < %d
			WHERE p.post_type = %s
			GROUP BY p.ID
			HAVING impressions > 0 OR clicks > 0
			ORDER BY impressions DESC
			LIMIT %d OFFSET %d",
			array_merge(
				$has_clicks ? array( $start_ts, $end_ts ) : array(),
				array( $start_ts, $end_ts, $this->advanced_ads_post_type(), max( 1, absint( $limit ) ), max( 0, absint( $offset ) ) )
			)
		);
		$rows = $wpdb->get_results( $query, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private function advanced_ads_row( $row ) {
		$impressions = absint( $row['impressions'] ?? 0 );
		$clicks = absint( $row['clicks'] ?? 0 );
		$id = absint( $row['id'] ?? 0 );
		$name = sanitize_text_field( $row['name'] ?? __( 'Untitled ad', 'stw-dashboard-mailing-stats' ) );
		return array(
			'id'          => $id,
			'name'        => $name,
			'bannerType'  => $this->advanced_ads_banner_type( $id, $name ),
			'status'      => sanitize_key( $row['status'] ?? 'unknown' ),
			'impressions' => $impressions,
			'clicks'      => $clicks,
			'ctr'         => $this->rate( $clicks, $impressions ),
			'lastUpdated' => $this->iso_date( $row['last_updated'] ?? '' ),
		);
	}

	private function advanced_ads_totals( $impressions_table, $clicks_table, $start_ts, $end_ts ) {
		global $wpdb;
		$impressions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(count), 0) FROM {$impressions_table} WHERE timestamp >= %d AND timestamp < %d",
				$start_ts,
				$end_ts
			)
		);
		$clicks = 0;
		if ( $clicks_table ) {
			$clicks = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(count), 0) FROM {$clicks_table} WHERE timestamp >= %d AND timestamp < %d",
					$start_ts,
					$end_ts
				)
			);
		}
		return array( 'impressions' => $impressions, 'clicks' => $clicks );
	}

	private function advanced_ads_timeseries( $impressions_table, $clicks_table, $start, $end, $start_ts, $end_ts ) {
		global $wpdb;
		$impressions = $this->advanced_ads_daily_counts( $impressions_table, $start_ts, $end_ts );
		$clicks = $clicks_table ? $this->advanced_ads_daily_counts( $clicks_table, $start_ts, $end_ts ) : array();
		$points = array();
		$current = strtotime( $start . ' 00:00:00' );
		$last = strtotime( $end . ' 00:00:00' );

		while ( $current && $last && $current <= $last ) {
			$date = gmdate( 'Y-m-d', $current );
			$points[] = array(
				'date'      => $date,
				'value'     => (float) ( $impressions[ $date ] ?? 0 ),
				'secondary' => (float) ( $clicks[ $date ] ?? 0 ),
			);
			$current = strtotime( '+1 day', $current );
		}

		return $points;
	}

	private function advanced_ads_daily_counts( $table, $start_ts, $end_ts ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT (`timestamp` - (`timestamp` %% 100)) day_key, COALESCE(SUM(`count`), 0) total
				FROM {$table}
				WHERE `timestamp` >= %d AND `timestamp` < %d
				GROUP BY day_key
				ORDER BY day_key ASC",
				$start_ts,
				$end_ts
			),
			ARRAY_A
		);
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$date = $this->advanced_ads_date_from_timestamp( $row['day_key'] ?? '' );
			if ( '' !== $date ) {
				$counts[ $date ] = absint( $row['total'] ?? 0 );
			}
		}
		return $counts;
	}

	private function advanced_ads_breakdown( array $rows, $total_impressions ) {
		$breakdown = array();
		foreach ( array_slice( $rows, 0, 8 ) as $row ) {
			$impressions = absint( $row['impressions'] ?? 0 );
			if ( $impressions <= 0 ) {
				continue;
			}
			$breakdown[] = array(
				'label' => sanitize_text_field( $row['name'] ?? __( 'Untitled ad', 'stw-dashboard-mailing-stats' ) ),
				'value' => (float) $impressions,
				'share' => (float) $this->rate( $impressions, $total_impressions ),
			);
		}
		return $breakdown;
	}

	private function advanced_ads_banner_performance( array $rows, $start, $end ) {
		$order = array( 'Billboard', 'Med rect', 'Wide Skyscraper', 'Mobile Fallback', 'Newsletter' );
		$colors = array(
			'Billboard'        => '#f09aa0',
			'Med rect'         => '#b9dcae',
			'Wide Skyscraper'  => '#b9b9b9',
			'Mobile Fallback'  => '#ffe79c',
			'Newsletter'       => '#b7d4f4',
		);
		$grouped = array();
		foreach ( $rows as $row ) {
			$type = $row['bannerType'] ?? 'Other';
			if ( ! isset( $grouped[ $type ] ) ) {
				$grouped[ $type ] = array( 'impressions' => 0, 'clicks' => 0 );
			}
			$grouped[ $type ]['impressions'] += absint( $row['impressions'] ?? 0 );
			$grouped[ $type ]['clicks'] += absint( $row['clicks'] ?? 0 );
		}

		$days = max( 1, (int) floor( ( strtotime( $end . ' 00:00:00' ) - strtotime( $start . ' 00:00:00' ) ) / DAY_IN_SECONDS ) + 1 );
		$weeks = max( 1 / 7, $days / 7 );
		$types = array_values( array_unique( array_merge( $order, array_keys( $grouped ) ) ) );
		$rows = array();
		foreach ( $types as $type ) {
			$impressions = absint( $grouped[ $type ]['impressions'] ?? 0 );
			$clicks = absint( $grouped[ $type ]['clicks'] ?? 0 );
			if ( $impressions <= 0 && $clicks <= 0 ) {
				continue;
			}
			$rows[] = array(
				'banner'             => $type,
				'impressions'        => $impressions,
				'clicks'             => $clicks,
				'impressionsPerWeek' => round( $impressions / $weeks, 1 ),
				'clicksPerWeek'      => round( $clicks / $weeks, 1 ),
				'ctr'                => $this->rate( $clicks, $impressions ),
				'color'              => $colors[ $type ] ?? '#d8ddd2',
			);
		}
		return $rows;
	}

	private function advanced_ads_banner_type( $ad_id, $name ) {
		$haystack = strtolower( $name . ' ' . $this->advanced_ads_meta_text( $ad_id ) );
		$rules = array(
			'Newsletter'      => array( 'newsletter', 'nl ', ' nl', 'mailing' ),
			'Mobile Fallback' => array( 'mobile fallback', 'mobile-fallback', 'fallback mobile' ),
			'Wide Skyscraper' => array( 'wide skyscraper', 'skyscraper', 'sky scraper' ),
			'Med rect'        => array( 'med rect', 'medium rectangle', 'medium-rectangle', 'rectangle', 'mrec' ),
			'Billboard'       => array( 'billboard' ),
		);
		foreach ( $rules as $label => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					return $label;
				}
			}
		}
		return __( 'Other', 'stw-dashboard-mailing-stats' );
	}

	private function advanced_ads_meta_text( $ad_id ) {
		$meta = get_post_meta( absint( $ad_id ) );
		if ( ! is_array( $meta ) ) {
			return '';
		}
		$parts = array();
		foreach ( $meta as $key => $values ) {
			if ( ! preg_match( '/advads|advanced|banner|placement|size|width|height|type/i', (string) $key ) ) {
				continue;
			}
			$parts[] = (string) $key;
			foreach ( (array) $values as $value ) {
				$parts[] = $this->advanced_ads_meta_value_text( maybe_unserialize( $value ) );
			}
		}
		return implode( ' ', $parts );
	}

	private function advanced_ads_meta_value_text( $value ) {
		if ( is_array( $value ) ) {
			return implode( ' ', array_map( array( $this, 'advanced_ads_meta_value_text' ), $value ) );
		}
		if ( is_object( $value ) ) {
			return $this->advanced_ads_meta_value_text( get_object_vars( $value ) );
		}
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	private function advanced_ads_count() {
		$query = new WP_Query(
			array(
				'post_type'      => $this->advanced_ads_post_type(),
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'advanced_ads_expired' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return (int) $query->found_posts;
	}

	private function advanced_ads_post_type() {
		return defined( 'Advanced_Ads::POST_TYPE_SLUG' ) ? Advanced_Ads::POST_TYPE_SLUG : 'advanced_ads';
	}

	private function advanced_ads_timestamp( $datetime ) {
		$timestamp = strtotime( get_gmt_from_date( $datetime ) );
		$local = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'ymWdH' );
		return absint( $local );
	}

	private function advanced_ads_date_from_timestamp( $timestamp ) {
		$value = str_pad( (string) absint( $timestamp ), 10, '0', STR_PAD_LEFT );
		$year = absint( substr( $value, 0, 2 ) );
		$month = absint( substr( $value, 2, 2 ) );
		$day = absint( substr( $value, 6, 2 ) );
		if ( $year <= 0 || $month <= 0 || $day <= 0 ) {
			return '';
		}
		return sprintf( '20%02d-%02d-%02d', $year, $month, $day );
	}
}
