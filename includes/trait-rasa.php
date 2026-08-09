<?php
/**
 * rasa.io stats provider.
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait STW_Dashboard_Mailing_Stats_Rasa {
	private function rasa_provider( $start, $end ) {
		$empty = array(
			'provider'    => 'rasa',
			'subscribers' => array( 'total' => 0, 'subscribed' => 0, 'unsubscribed' => 0, 'bounced' => 0, 'inactive' => 0 ),
			'lists'       => array(),
			'campaigns'   => array(),
		);
		$token = $this->rasa_token();
		if ( ! $token ) {
			return $empty;
		}

		$people = $this->rasa_people_counts( $token );
		$total = absint( $people['total'] ?? 0 );
		$subscribed = absint( $people['subscribed'] ?? 0 );
		$not_receiving = absint( $people['unsubscribed'] ?? 0 );
		if ( 0 === $total ) {
			$total = $this->rasa_person_count( $token, array() );
			$subscribed = $this->rasa_person_count_any(
				$token,
				array(
					array( 'is_subscribed' => '1' ),
					array( 'is_subscribed' => 'true' ),
					array( 'is_receiving'  => '1' ),
					array( 'is_receiving'  => 'true' ),
				)
			);
			$not_receiving = $this->rasa_person_count_any(
				$token,
				array(
					array( 'is_subscribed' => '0' ),
					array( 'is_subscribed' => 'false' ),
					array( 'is_receiving'  => '0' ),
					array( 'is_receiving'  => 'false' ),
				)
			);
			if ( $total > 0 ) {
				if ( $subscribed <= 0 && $not_receiving > 0 ) {
					$subscribed = max( 0, $total - $not_receiving );
				}
				if ( $not_receiving <= 0 && $subscribed > 0 ) {
					$not_receiving = max( 0, $total - $subscribed );
				}
			}
			$total = max( $total, $subscribed + $not_receiving );
		}
		$activity = $this->rasa_activity( $token, $start, $end );
		$movement = $this->rasa_subscriber_movement( $token, $start, $end, $subscribed, $total );

		return array(
			'provider'    => 'rasa',
			'subscribers' => array(
				'total'        => $total,
				'subscribed'   => $subscribed,
				'unsubscribed' => $not_receiving,
				'bounced'      => absint( $activity['bounces'] ?? 0 ),
				'inactive'     => 0,
			),
			'period'      => array(
				'emailsSent'    => absint( $activity['delivered'] ?? 0 ),
				'opens'         => absint( $activity['opens'] ?? 0 ),
				'machineOpens'  => 0,
				'clicks'        => absint( $activity['clicks'] ?? 0 ),
				'unsubscribes'  => absint( $activity['unsubscribes'] ?? 0 ),
				'bounces'       => absint( $activity['bounces'] ?? 0 ),
				'campaigns'     => 1,
				'newSubscribers' => $movement['new'],
				'unsubscribedSubscribers' => $movement['unsubscribed'],
			),
			'movement'    => $movement,
			'lists'       => array(
				array(
					'id'         => 'rasa-v1',
					'name'       => __( 'rasa active recipients', 'stw-dashboard-mailing-stats' ),
					'subscribed' => $subscribed,
					'total'      => $total,
					'updatedAt'  => gmdate( 'c' ),
				),
			),
			'campaigns'   => array(
				array(
					'id'           => 'rasa-' . $start . '-' . $end,
					'name'         => __( 'rasa activity analytics', 'stw-dashboard-mailing-stats' ),
					'sent'         => absint( $activity['delivered'] ?? 0 ),
					'opens'        => absint( $activity['opens'] ?? 0 ),
					'machineOpens' => 0,
					'clicks'       => absint( $activity['clicks'] ?? 0 ),
					'unsubscribes' => absint( $activity['unsubscribes'] ?? 0 ),
					'bounces'      => absint( $activity['bounces'] ?? 0 ),
					'sentAt'       => gmdate( 'c' ),
				),
			),
			'debug'       => $this->rasa_debug,
		);
	}

	private function rasa_token() {
		$username = $this->rasa_username();
		$password = $this->rasa_password();
		$api_key = $this->rasa_api_key();
		if ( '' === $username || '' === $password || '' === $api_key ) {
			return '';
		}

		$cache_key = 'stw_dashboard_rasa_token_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $username . '|' . substr( $api_key, 0, 8 ) );
		return (string) $this->cached_fragment(
			$cache_key,
			50 * MINUTE_IN_SECONDS,
			function () use ( $username, $password, $api_key ) {
				$response = wp_remote_post(
					$this->rasa_url( 'tokens' ),
					array(
						'timeout' => 20,
						'headers' => array(
							'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
							'Content-Type'  => 'application/json',
						),
						'body'    => wp_json_encode( array( 'key' => $api_key ) ),
					)
				);

				$body = $this->remote_json( $response );
				return (string) ( $body['results'][0]['token'] ?? $body['results'][0]['rasa-token'] ?? '' );
			},
			HOUR_IN_SECONDS
		);
	}

	private function rasa_person_count( $token, array $query_args ) {
		$query_args = array_merge(
			$query_args,
			array(
				'limit'     => 1,
				'page_size' => 1,
				'pageSize'  => 1,
				'per_page'  => 1,
			)
		);
		$response = wp_remote_get( add_query_arg( $query_args, $this->rasa_url( 'persons' ) ), $this->rasa_request_args( $token ) );
		$body = $this->remote_json( $response );
		return absint( $this->rasa_metadata_total( $body ) ?: count( $body['results'] ?? array() ) );
	}

	private function rasa_person_count_any( $token, array $queries ) {
		foreach ( $queries as $query_args ) {
			$count = $this->rasa_person_count( $token, $query_args );
			if ( $count > 0 ) {
				return $count;
			}
		}
		return 0;
	}

	private function rasa_subscriber_movement( $token, $start, $end, $subscribed, $total ) {
		$cache_key = 'stw_dashboard_rasa_movement_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $subscribed . '|' . $total );
		return $this->cached_fragment(
			$cache_key,
			30 * MINUTE_IN_SECONDS,
			function () use ( $token, $start, $end, $subscribed, $total ) {
		$new = $this->rasa_person_count_for_queries(
			$token,
			array(
				array( 'created_after' => $start . 'T00:00:00Z', 'created_before' => $end . 'T23:59:59Z' ),
				array( 'created_at_from' => $start . 'T00:00:00Z', 'created_at_to' => $end . 'T23:59:59Z' ),
				array( 'created_from' => $start . 'T00:00:00Z', 'created_to' => $end . 'T23:59:59Z' ),
				array( 'start_date' => $start . 'T00:00:00Z', 'end_date' => $end . 'T23:59:59Z' ),
			)
		);
		$unsubscribed = $this->rasa_person_count_for_queries(
			$token,
			array(
				array( 'is_subscribed' => 'false', 'updated_after' => $start . 'T00:00:00Z', 'updated_before' => $end . 'T23:59:59Z' ),
				array( 'is_receiving' => 'false', 'updated_after' => $start . 'T00:00:00Z', 'updated_before' => $end . 'T23:59:59Z' ),
				array( 'status' => 'unsubscribed', 'updated_at_from' => $start . 'T00:00:00Z', 'updated_at_to' => $end . 'T23:59:59Z' ),
				array( 'subscription_status' => 'unsubscribed', 'updated_at_from' => $start . 'T00:00:00Z', 'updated_at_to' => $end . 'T23:59:59Z' ),
			)
		);
		return array(
			'new'          => $new['count'],
			'unsubscribed' => $unsubscribed['count'],
			'lists'        => array(
				array(
					'provider'     => 'rasa',
					'id'           => 'rasa-v1',
					'name'         => __( 'rasa active recipients', 'stw-dashboard-mailing-stats' ),
					'listScore'    => $this->rate( $subscribed, max( 1, $total ) ),
					'quality'      => $this->mailing_list_quality( $this->rate( $subscribed, max( 1, $total ) ) ),
					'new'          => $new['count'],
					'unsubscribed' => $unsubscribed['count'],
				),
			),
			'source'       => 'rasa-persons',
			'debug'        => array( 'new' => $new['source'], 'unsubscribed' => $unsubscribed['source'] ),
		);
			},
			6 * HOUR_IN_SECONDS
		);
	}

	private function rasa_person_count_for_queries( $token, array $queries ) {
		foreach ( $queries as $query_args ) {
			$counts = $this->rasa_people_counts_from_pages( $token, 0, $query_args );
			$count = absint( $counts['total'] ?? 0 );
			if ( $count > 0 ) {
				return array( 'count' => $count, 'source' => array_merge( array( 'query' => $query_args ), $counts ) );
			}
		}
		return array( 'count' => 0, 'source' => array( 'query' => null, 'stopReason' => 'no-filter-count' ) );
	}

	private function rasa_people_counts( $token ) {
		$cache_key = 'stw_dashboard_rasa_people_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $this->rasa_username() );
		return $this->cached_fragment(
			$cache_key,
			30 * MINUTE_IN_SECONDS,
			function () use ( $token ) {
		$this->rasa_debug = array(
			'cacheVersion' => self::CACHE_VERSION,
			'queryCounts'  => null,
			'strategies'   => array(),
			'selected'     => null,
		);
		$query_counts = $this->rasa_people_query_counts( $token );
		$this->rasa_debug['queryCounts'] = $query_counts;
		if ( $this->rasa_people_counts_are_usable( $query_counts ) ) {
			$this->rasa_debug['selected'] = array_merge( array( 'source' => 'query-counts' ), $query_counts );
			return $query_counts;
		}

		$counts = $this->rasa_people_counts_from_pages( $token, absint( $query_counts['total'] ?? 0 ) );
		if ( $counts['total'] <= 0 ) {
			return $query_counts;
		}

		if ( $query_counts['total'] > $counts['total'] ) {
			$unknown = $query_counts['total'] - $counts['total'];
			$counts['total'] = $query_counts['total'];
			if ( $query_counts['subscribed'] > 0 && $query_counts['subscribed'] <= $counts['total'] ) {
				$counts['subscribed'] = $query_counts['subscribed'];
				$counts['unsubscribed'] = max( 0, $counts['total'] - $counts['subscribed'] );
			} else {
				$counts['subscribed'] += $unknown;
			}
		}

		$this->rasa_debug['selected'] = array_merge( array( 'source' => 'pagination' ), $counts );
		return $counts;
			},
			6 * HOUR_IN_SECONDS
		);
	}

	private function rasa_people_query_counts( $token ) {
		$total = $this->rasa_person_count( $token, array() );
		$subscribed = $this->rasa_person_count_any(
			$token,
			array(
				array( 'is_subscribed' => '1' ),
				array( 'is_subscribed' => 'true' ),
				array( 'is_receiving'  => '1' ),
				array( 'is_receiving'  => 'true' ),
				array( 'status'        => 'subscribed' ),
				array( 'subscription_status' => 'subscribed' ),
			)
		);
		$unsubscribed = $this->rasa_person_count_any(
			$token,
			array(
				array( 'is_subscribed' => '0' ),
				array( 'is_subscribed' => 'false' ),
				array( 'is_receiving'  => '0' ),
				array( 'is_receiving'  => 'false' ),
				array( 'status'        => 'unsubscribed' ),
				array( 'status'        => 'inactive' ),
				array( 'subscription_status' => 'unsubscribed' ),
				array( 'subscription_status' => 'inactive' ),
			)
		);

		return array(
			'total'        => $total,
			'subscribed'   => $subscribed,
			'unsubscribed' => $unsubscribed,
		);
	}

	private function rasa_people_counts_are_usable( array $counts ) {
		$total = absint( $counts['total'] ?? 0 );
		$subscribed = absint( $counts['subscribed'] ?? 0 );
		$unsubscribed = absint( $counts['unsubscribed'] ?? 0 );
		if ( $total <= 0 || $subscribed + $unsubscribed <= 0 ) {
			return false;
		}
		if ( 0 === $unsubscribed && 0 === $total % 1000 ) {
			return false;
		}

		return $subscribed + $unsubscribed <= $total;
	}

	private function rasa_people_counts_from_pages( $token, $expected_total, array $base_query_args = array() ) {
		$limit = 1000;
		$best_counts = array( 'total' => 0, 'subscribed' => 0, 'unsubscribed' => 0 );
		foreach ( array( 'skip', 'offset', 'page', 'page_number' ) as $strategy ) {
			$counts = $this->rasa_people_counts_from_page_strategy( $token, $expected_total, $limit, $strategy, $base_query_args );
			$this->rasa_debug['strategies'][] = array_merge( array( 'strategy' => $strategy ), $counts );
			if ( $counts['total'] > $best_counts['total'] ) {
				$best_counts = $counts;
			}
			if ( $counts['total'] > $limit ) {
				return $counts;
			}
		}
		return $best_counts;
	}

	private function rasa_people_counts_from_page_strategy( $token, $expected_total, $limit, $strategy, array $base_query_args = array() ) {
		$counts = array( 'total' => 0, 'subscribed' => 0, 'unsubscribed' => 0 );
		$seen_first_signature = '';
		$metadata_total = absint( $expected_total );
		$pages_fetched = 0;
		$stop_reason = 'page-limit';

		for ( $page = 0; $page < 50; ++$page ) {
			$query_args = array_merge(
				$base_query_args,
				array(
				'limit'     => $limit,
				'page_size' => $limit,
				'pageSize'  => $limit,
				'per_page'  => $limit,
				)
			);
			if ( 'skip' === $strategy ) {
				$query_args['skip'] = $page * $limit;
			} elseif ( 'offset' === $strategy ) {
				$query_args['offset'] = $page * $limit;
			} elseif ( 'page' === $strategy ) {
				$query_args['page'] = $page + 1;
				$query_args['page_number'] = $page + 1;
			} else {
				$query_args['page_number'] = $page + 1;
				$query_args['page'] = $page + 1;
			}

			$body = $this->remote_json( wp_remote_get( add_query_arg( $query_args, $this->rasa_url( 'persons' ) ), $this->rasa_request_args( $token ) ) );
			$results = isset( $body['results'] ) && is_array( $body['results'] ) ? $body['results'] : array();
			if ( empty( $results ) ) {
				$stop_reason = 'empty-results';
				break;
			}
			++$pages_fetched;

			$page_metadata_total = $this->rasa_metadata_total( $body );
			if ( $page_metadata_total >= $counts['total'] + count( $results ) ) {
				$metadata_total = max( $metadata_total, $page_metadata_total );
			}

			foreach ( $results as $item ) {
				$person = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : $item;
				if ( ! is_array( $person ) ) {
					continue;
				}
				++$counts['total'];
				if ( $this->rasa_person_is_subscribed( $person ) ) {
					++$counts['subscribed'];
				} else {
					++$counts['unsubscribed'];
				}
			}

			$first_person = isset( $results[0]['data'] ) && is_array( $results[0]['data'] ) ? $results[0]['data'] : $results[0];
			$signature = is_array( $first_person ) ? $this->rasa_person_signature( $first_person ) : '';
			if ( $page > 0 && '' !== $signature && $signature === $seen_first_signature ) {
				$counts['total'] -= count( $results );
				$counts = $this->rasa_recount_without_page( $counts, $results );
				$stop_reason = 'duplicate-first-record';
				break;
			}
			if ( 0 === $page ) {
				$seen_first_signature = $signature;
			}

			$next_offset = ( $page + 1 ) * $limit;
			$trust_metadata_total = $metadata_total > $counts['total'] && ( $metadata_total > $limit || 0 !== $metadata_total % $limit );
			if ( count( $results ) < $limit || ( $trust_metadata_total && $next_offset >= $metadata_total ) ) {
				$stop_reason = count( $results ) < $limit ? 'short-page' : 'metadata-total-reached';
				break;
			}
		}

		if ( $metadata_total > $counts['total'] ) {
			$unknown = $metadata_total - $counts['total'];
			$counts['total'] = $metadata_total;
			if ( 0 === $counts['unsubscribed'] ) {
				$counts['subscribed'] += $unknown;
			}
		}

		return array_merge(
			$counts,
			array(
				'pagesFetched'  => $pages_fetched,
				'metadataTotal' => $metadata_total,
				'stopReason'    => $stop_reason,
			)
		);
	}

	private function rasa_recount_without_page( array $counts, array $results ) {
		foreach ( $results as $item ) {
			$person = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : $item;
			if ( ! is_array( $person ) ) {
				continue;
			}
			if ( $this->rasa_person_is_subscribed( $person ) ) {
				$counts['subscribed'] = max( 0, $counts['subscribed'] - 1 );
			} else {
				$counts['unsubscribed'] = max( 0, $counts['unsubscribed'] - 1 );
			}
		}
		return $counts;
	}

	private function rasa_metadata_total( array $body ) {
		foreach ( array( 'metadata', 'meta', 'pagination', 'paging' ) as $container_key ) {
			if ( isset( $body[ $container_key ] ) && is_array( $body[ $container_key ] ) ) {
				$total = $this->rasa_metadata_total_value( $body[ $container_key ] );
				if ( $total > 0 ) {
					return $total;
				}
			}
		}
		return $this->rasa_metadata_total_value( $body );
	}

	private function rasa_metadata_total_value( array $metadata ) {
		foreach (
			array(
				'total_query_count',
				'totalQueryCount',
				'record_count',
				'recordCount',
				'total_count',
				'totalCount',
				'total_records',
				'totalRecords',
				'total_results',
				'totalResults',
				'total',
			) as $key
		) {
			if ( isset( $metadata[ $key ] ) ) {
				return absint( $metadata[ $key ] );
			}
		}
		return 0;
	}

	private function rasa_person_signature( array $person ) {
		foreach ( array( 'id', 'person_id', 'email', 'email_address' ) as $key ) {
			if ( isset( $person[ $key ] ) && '' !== (string) $person[ $key ] ) {
				return (string) $person[ $key ];
			}
		}
		return md5( wp_json_encode( $person ) );
	}

	private function rasa_person_is_subscribed( array $person ) {
		$status = $person['is_subscribed'] ?? $person['is_receiving'] ?? $person['status'] ?? $person['subscription_status'] ?? true;
		if ( is_bool( $status ) ) {
			return $status;
		}
		$status = strtolower( trim( (string) $status ) );
		return ! in_array( $status, array( '0', 'false', 'no', 'unsubscribed', 'inactive' ), true );
	}

	private function rasa_activity( $token, $start, $end ) {
		$cache_key = 'stw_dashboard_rasa_activity_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end );
		return $this->cached_fragment(
			$cache_key,
			15 * MINUTE_IN_SECONDS,
			function () use ( $token, $start, $end ) {
				$response = wp_remote_post(
					$this->rasa_url( 'analytics/activities' ),
					array_merge(
						$this->rasa_request_args( $token ),
						array(
							'headers' => array_merge(
								$this->rasa_request_args( $token )['headers'],
								array( 'Content-Type' => 'application/json' )
							),
							'body'    => wp_json_encode(
								array(
									'date_range'    => array(
										'start_date' => $start . 'T00:00:00Z',
										'end_date'   => $end . 'T23:59:59Z',
									),
									'interval'      => 'day',
									'metrics'       => array( 'open', 'click', 'delivered', 'bounce', 'unsubscribe' ),
									'suspect_click' => 'real_clicks',
									'segment_code'  => 'All',
									'timezone'      => 'UTC',
									'limit'         => 10000,
								)
							),
						)
					)
				);
				$body = $this->remote_json( $response );
				$totals = array( 'opens' => 0, 'clicks' => 0, 'delivered' => 0, 'bounces' => 0, 'unsubscribes' => 0 );
				foreach ( $body['results'] ?? array() as $row ) {
					$totals['opens'] += $this->rasa_metric_value( $row, array( 'total_opens', 'opens', 'open', 'unique_opens', 'unique_open' ) );
					$totals['clicks'] += $this->rasa_metric_value( $row, array( 'total_clicks', 'clicks', 'click', 'unique_clicks', 'unique_click' ) );
					$totals['delivered'] += $this->rasa_metric_value( $row, array( 'total_delivered', 'delivered', 'deliveries', 'delivery' ) );
					$totals['bounces'] += $this->rasa_metric_value( $row, array( 'total_bounces', 'bounces', 'bounce', 'total_bounce' ) );
					$totals['unsubscribes'] += $this->rasa_metric_value( $row, array( 'total_unsubscribes', 'unsubscribes', 'unsubscribe', 'unsubscribed' ) );
				}
				return $totals;
			},
			6 * HOUR_IN_SECONDS
		);
	}

	private function rasa_metric_value( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) ) {
				return absint( $row[ $key ] );
			}
		}
		foreach ( array( 'data', 'metrics', 'totals', 'values' ) as $container_key ) {
			if ( isset( $row[ $container_key ] ) && is_array( $row[ $container_key ] ) ) {
				foreach ( $keys as $key ) {
					if ( isset( $row[ $container_key ][ $key ] ) ) {
						return absint( $row[ $container_key ][ $key ] );
					}
				}
			}
		}
		return 0;
	}

	private function rasa_request_args( $token ) {
		return array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->rasa_username() . ':' . $this->rasa_password() ),
				'rasa-token'    => $token,
			),
		);
	}

}
