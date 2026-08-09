<?php
/**
 * MailPoet stats provider.
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait STW_Dashboard_Mailing_Stats_MailPoet {
	private function mailpoet_provider( $start, $end, $limit ) {
		$campaigns = $this->mailpoet_campaigns( $start, $end, $limit );
		$period = $this->mailing_period_from_campaigns( $campaigns );
		$lists = $this->mailpoet_lists();
		$movement = $this->mailpoet_subscriber_movement( $start, $end, $lists );
		return array(
			'provider'    => 'MailPoet',
			'subscribers' => $this->mailpoet_subscriber_counts(),
			'period'      => array_merge( $period, array( 'newSubscribers' => $movement['new'], 'unsubscribedSubscribers' => $movement['unsubscribed'] ) ),
			'movement'    => $movement,
			'lists'       => $lists,
			'campaigns'   => $campaigns,
		);
	}

	private function mailpoet_api() {
		if ( class_exists( '\MailPoet\API\API' ) ) {
			try {
				return \MailPoet\API\API::MP( 'v1' );
			} catch ( Exception $e ) {
				return null;
			}
		}
		return null;
	}

	private function mailpoet_subscriber_counts() {
		$cache_key = 'stw_dashboard_mailpoet_counts_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() );
		return $this->cached_fragment(
			$cache_key,
			30 * MINUTE_IN_SECONDS,
			function () {
				$api = $this->mailpoet_api();
				if ( ! $api ) {
					return array( 'total' => 0, 'subscribed' => 0, 'unsubscribed' => 0, 'bounced' => 0, 'inactive' => 0 );
				}
				return array(
					'total'        => (int) $api->getSubscribersCount(),
					'subscribed'   => (int) $api->getSubscribersCount( array( 'status' => 'subscribed' ) ),
					'unsubscribed' => (int) $api->getSubscribersCount( array( 'status' => 'unsubscribed' ) ),
					'bounced'      => (int) $api->getSubscribersCount( array( 'status' => 'bounced' ) ),
					'inactive'     => (int) $api->getSubscribersCount( array( 'status' => 'inactive' ) ),
				);
			},
			6 * HOUR_IN_SECONDS
		);
	}

	private function mailpoet_lists() {
		$cache_key = 'stw_dashboard_mailpoet_lists_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() );
		return $this->cached_fragment(
			$cache_key,
			30 * MINUTE_IN_SECONDS,
			function () {
				$api = $this->mailpoet_api();
				if ( ! $api ) {
					return array();
				}
				$lists = array();
				foreach ( $api->getLists() as $list ) {
					if ( ! empty( $list['deleted_at'] ) ) {
						continue;
					}
					$id = absint( $list['id'] ?? 0 );
					$lists[] = array(
						'id'         => (string) $id,
						'name'       => sanitize_text_field( $list['name'] ?? '' ),
						'subscribed' => (int) $api->getSubscribersCount( array( 'status' => 'subscribed', 'listId' => $id ) ),
						'total'      => (int) $api->getSubscribersCount( array( 'listId' => $id ) ),
						'updatedAt'  => $this->iso_date( $list['updated_at'] ?? '' ),
					);
				}
				return $lists;
			},
			6 * HOUR_IN_SECONDS
		);
	}

	private function mailpoet_campaigns( $start, $end, $limit ) {
		global $wpdb;
		$tables = array(
			'newsletters' => $wpdb->prefix . 'mailpoet_newsletters',
			'sent'        => $wpdb->prefix . 'mailpoet_statistics_newsletters',
			'opens'       => $wpdb->prefix . 'mailpoet_statistics_opens',
			'clicks'      => $wpdb->prefix . 'mailpoet_statistics_clicks',
			'unsubs'      => $wpdb->prefix . 'mailpoet_statistics_unsubscribes',
			'bounces'     => $wpdb->prefix . 'mailpoet_statistics_bounces',
		);
		foreach ( $tables as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				return array();
			}
		}

		$start_at = $start . ' 00:00:00';
		$end_at = $end . ' 23:59:59';
		$query = $wpdb->prepare(
			"SELECT n.id, n.subject, n.sent_at,
				(SELECT COUNT(DISTINCT s.subscriber_id) FROM {$tables['sent']} s WHERE s.newsletter_id = n.id) sent,
				(SELECT COUNT(DISTINCT o.subscriber_id) FROM {$tables['opens']} o WHERE o.newsletter_id = n.id AND o.user_agent_type = 0) opens,
				(SELECT COUNT(DISTINCT o.subscriber_id) FROM {$tables['opens']} o WHERE o.newsletter_id = n.id AND o.user_agent_type = 1) machine_opens,
				(SELECT COUNT(DISTINCT c.subscriber_id) FROM {$tables['clicks']} c WHERE c.newsletter_id = n.id) clicks,
				(SELECT COUNT(DISTINCT u.subscriber_id) FROM {$tables['unsubs']} u WHERE u.newsletter_id = n.id) unsubscribes,
				(SELECT COUNT(DISTINCT b.subscriber_id) FROM {$tables['bounces']} b WHERE b.newsletter_id = n.id) bounces
			FROM {$tables['newsletters']} n
			WHERE n.deleted_at IS NULL AND n.sent_at BETWEEN %s AND %s
			ORDER BY n.sent_at DESC
			LIMIT %d",
			$start_at,
			$end_at,
			$limit
		);
		$rows = $wpdb->get_results( $query, ARRAY_A );

		return array_map(
			function ( $row ) {
				return array(
					'id'            => (string) absint( $row['id'] ?? 0 ),
					'name'          => sanitize_text_field( $row['subject'] ?? __( 'MailPoet newsletter', 'stw-dashboard-mailing-stats' ) ),
					'sent'          => absint( $row['sent'] ?? 0 ),
					'opens'         => absint( $row['opens'] ?? 0 ),
					'machineOpens'  => absint( $row['machine_opens'] ?? 0 ),
					'clicks'        => absint( $row['clicks'] ?? 0 ),
					'unsubscribes'  => absint( $row['unsubscribes'] ?? 0 ),
					'bounces'       => absint( $row['bounces'] ?? 0 ),
					'sentAt'        => $this->iso_date( $row['sent_at'] ?? '' ),
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	private function mailpoet_subscriber_movement( $start, $end, array $lists ) {
		$list_ids = implode( ',', array_map( 'absint', wp_list_pluck( $lists, 'id' ) ) );
		$cache_key = 'stw_dashboard_mailpoet_movement_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $list_ids );
		return $this->cached_fragment(
			$cache_key,
			30 * MINUTE_IN_SECONDS,
			function () use ( $start, $end, $lists ) {
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'mailpoet_subscribers';
		$segments_table = $wpdb->prefix . 'mailpoet_subscriber_segment';
		if ( ! $this->table_exists( $subscribers_table ) || ! $this->table_exists( $segments_table ) ) {
			return array( 'new' => 0, 'unsubscribed' => 0, 'lists' => array(), 'source' => 'mailpoet-unavailable' );
		}

		$new_column = $this->first_existing_column( $subscribers_table, array( 'created_at', 'createdAt' ) );
		$unsubscribed_column = $this->first_existing_column( $subscribers_table, array( 'unsubscribed_at', 'unsubscribedAt', 'updated_at', 'updatedAt' ) );
		if ( '' === $new_column || '' === $unsubscribed_column ) {
			return array( 'new' => 0, 'unsubscribed' => 0, 'lists' => array(), 'source' => 'mailpoet-columns-missing' );
		}

		$start_at = $start . ' 00:00:00';
		$end_at = $end . ' 23:59:59';
		$list_rows = array();
		$total_new = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT s.id) FROM {$subscribers_table} s WHERE s.{$new_column} BETWEEN %s AND %s",
				$start_at,
				$end_at
			)
		);
		$total_unsubscribed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT s.id) FROM {$subscribers_table} s WHERE s.status = %s AND s.{$unsubscribed_column} BETWEEN %s AND %s",
				'unsubscribed',
				$start_at,
				$end_at
			)
		);
		foreach ( $lists as $list ) {
			$list_id = absint( $list['id'] ?? 0 );
			if ( $list_id <= 0 ) {
				continue;
			}
			$new = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT s.id)
					FROM {$subscribers_table} s
					INNER JOIN {$segments_table} ss ON ss.subscriber_id = s.id
					WHERE ss.segment_id = %d AND s.{$new_column} BETWEEN %s AND %s",
					$list_id,
					$start_at,
					$end_at
				)
			);
			$unsubscribed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT s.id)
					FROM {$subscribers_table} s
					INNER JOIN {$segments_table} ss ON ss.subscriber_id = s.id
					WHERE ss.segment_id = %d AND s.status = %s AND s.{$unsubscribed_column} BETWEEN %s AND %s",
					$list_id,
					'unsubscribed',
					$start_at,
					$end_at
				)
			);
			$list_rows[] = array(
				'provider'     => 'MailPoet',
				'id'           => (string) $list_id,
				'name'         => sanitize_text_field( $list['name'] ?? __( 'MailPoet list', 'stw-dashboard-mailing-stats' ) ),
				'listScore'    => $this->rate( absint( $list['subscribed'] ?? 0 ), max( 1, absint( $list['total'] ?? 0 ) ) ),
				'quality'      => $this->mailing_list_quality( $this->rate( absint( $list['subscribed'] ?? 0 ), max( 1, absint( $list['total'] ?? 0 ) ) ) ),
				'new'          => $new,
				'unsubscribed' => $unsubscribed,
			);
		}
		return array(
			'new'          => $total_new,
			'unsubscribed' => $total_unsubscribed,
			'lists'        => $list_rows,
			'source'       => 'mailpoet-tables',
		);
			},
			6 * HOUR_IN_SECONDS
		);
	}

	private function mailing_list_quality( $score ) {
		if ( $score >= 50 ) {
			return __( 'Excellent', 'stw-dashboard-mailing-stats' );
		}
		if ( $score >= 25 ) {
			return __( 'Good', 'stw-dashboard-mailing-stats' );
		}
		return __( 'Needs attention', 'stw-dashboard-mailing-stats' );
	}

	private function mailing_period_from_campaigns( array $campaigns ) {
		$period = array(
			'emailsSent'    => 0,
			'opens'         => 0,
			'machineOpens'  => 0,
			'clicks'        => 0,
			'unsubscribes'  => 0,
			'bounces'       => 0,
			'campaigns'     => count( $campaigns ),
		);
		foreach ( $campaigns as $campaign ) {
			$period['emailsSent'] += absint( $campaign['sent'] ?? 0 );
			$period['opens'] += absint( $campaign['opens'] ?? 0 );
			$period['machineOpens'] += absint( $campaign['machineOpens'] ?? 0 );
			$period['clicks'] += absint( $campaign['clicks'] ?? 0 );
			$period['unsubscribes'] += absint( $campaign['unsubscribes'] ?? 0 );
			$period['bounces'] += absint( $campaign['bounces'] ?? 0 );
		}
		return $period;
	}
}
