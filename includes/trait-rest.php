<?php
/**
 * REST route registration and response caching.
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait STW_Dashboard_Mailing_Stats_REST {
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/mailing/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_mailing_stats' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'startDate' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'endDate'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'      => array( 'sanitize_callback' => 'absint' ),
					'pageSize'  => array( 'sanitize_callback' => 'absint' ),
					'blogId'    => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/editorial/posts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_editorial_posts' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'startDate'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'endDate'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'       => array( 'sanitize_callback' => 'absint' ),
					'pageSize'   => array( 'sanitize_callback' => 'absint' ),
					'blogId'     => array( 'sanitize_callback' => 'absint' ),
					'search'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'authorId'   => array( 'sanitize_callback' => 'absint' ),
					'categoryId' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ads/(?P<view>summary|timeseries|top|table)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_ads_stats' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'view'      => array( 'sanitize_callback' => 'sanitize_key' ),
					'startDate' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'endDate'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'      => array( 'sanitize_callback' => 'absint' ),
					'pageSize'  => array( 'sanitize_callback' => 'absint' ),
					'blogId'    => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_all_stats' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'startDate' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'endDate'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'      => array( 'sanitize_callback' => 'absint' ),
					'pageSize'  => array( 'sanitize_callback' => 'absint' ),
					'blogId'    => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	public function rest_permission( WP_REST_Request $request ) {
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$switched = false;
		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$header = $request->get_header( 'authorization' );
		$token  = preg_replace( '/^Bearer\s+/i', '', (string) $header );
		$hash   = $this->dashboard_api_key_hash();

		$constant_key = $this->dashboard_api_key_constant();
		if ( '' !== $constant_key && hash_equals( $constant_key, $token ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return true;
		}

		if ( '' !== $hash && '' !== $token && wp_check_password( $token, $hash ) ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return true;
		}

		if ( $switched ) {
			restore_current_blog();
		}

		return new WP_Error( 'stw_dashboard_mailing_forbidden', __( 'Invalid mailing stats API token.', 'stw-dashboard-mailing-stats' ), array( 'status' => 401 ) );
	}

	public function rest_mailing_stats( WP_REST_Request $request ) {
		$started = microtime( true );
		$start = $this->date_arg( $request->get_param( 'startDate' ), gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$end   = $this->date_arg( $request->get_param( 'endDate' ), gmdate( 'Y-m-d' ) );
		$page_size = max( 1, min( 100, absint( $request->get_param( 'pageSize' ) ) ?: 25 ) );
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$switched = false;

		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$cache_key = 'stw_dashboard_mailing_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $page_size );
		$response = $this->cached_rest_payload(
			$cache_key,
			$started,
			function () use ( $start, $end, $page_size ) {
				return array(
					'providers' => array(
						$this->mailpoet_provider( $start, $end, $page_size ),
						$this->rasa_provider( $start, $end ),
					),
				);
			}
		);

		if ( $switched ) {
			restore_current_blog();
		}

		return $response;
	}

	public function rest_ads_stats( WP_REST_Request $request ) {
		$started = microtime( true );
		$start = $this->date_arg( $request->get_param( 'startDate' ), gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$end   = $this->date_arg( $request->get_param( 'endDate' ), gmdate( 'Y-m-d' ) );
		$page = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$page_size = max( 1, min( 100, absint( $request->get_param( 'pageSize' ) ) ?: 25 ) );
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$switched = false;

		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$cache_key = 'stw_dashboard_ads_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $page . '|' . $page_size );
		$response = $this->cached_rest_payload(
			$cache_key,
			$started,
			function () use ( $start, $end, $page, $page_size ) {
				return $this->advanced_ads_payload( $start, $end, $page, $page_size );
			}
		);

		if ( $switched ) {
			restore_current_blog();
		}

		return $response;
	}

	public function rest_editorial_posts( WP_REST_Request $request ) {
		$started = microtime( true );
		$start = $this->date_arg( $request->get_param( 'startDate' ), gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$end   = $this->date_arg( $request->get_param( 'endDate' ), gmdate( 'Y-m-d' ) );
		$page = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$page_size = max( 1, min( 100, absint( $request->get_param( 'pageSize' ) ) ?: 25 ) );
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$author_id = absint( $request->get_param( 'authorId' ) );
		$category_id = absint( $request->get_param( 'categoryId' ) );
		$switched = false;

		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$cache_key = 'stw_dashboard_editorial_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $page . '|' . $page_size . '|' . $search . '|' . $author_id . '|' . $category_id );
		$response = $this->cached_rest_payload(
			$cache_key,
			$started,
			function () use ( $start, $end, $page, $page_size, $search, $author_id, $category_id ) {
				return $this->editorial_posts_payload( $start, $end, $page, $page_size, $search, $author_id, $category_id );
			}
		);

		if ( $switched ) {
			restore_current_blog();
		}

		return $response;
	}

	public function rest_all_stats( WP_REST_Request $request ) {
		$started = microtime( true );
		$start = $this->date_arg( $request->get_param( 'startDate' ), gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$end   = $this->date_arg( $request->get_param( 'endDate' ), gmdate( 'Y-m-d' ) );
		$page = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$page_size = max( 1, min( 100, absint( $request->get_param( 'pageSize' ) ) ?: 25 ) );
		$blog_id = absint( $request->get_param( 'blogId' ) );
		$switched = false;

		if ( is_multisite() && $blog_id && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$cache_key = 'stw_dashboard_all_' . md5( self::CACHE_VERSION . '|' . get_current_blog_id() . '|' . $start . '|' . $end . '|' . $page . '|' . $page_size );
		$response = $this->cached_rest_payload(
			$cache_key,
			$started,
			function () use ( $start, $end, $page, $page_size ) {
				return array(
					'mailing' => array(
						'providers' => array(
							$this->mailpoet_provider( $start, $end, $page_size ),
							$this->rasa_provider( $start, $end ),
						),
					),
					'ads'     => $this->advanced_ads_payload( $start, $end, $page, $page_size ),
				);
			}
		);

		if ( $switched ) {
			restore_current_blog();
		}

		return $response;
	}

	private function cached_rest_payload( $cache_key, $started, $builder ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $this->cache_response( $cached, 'hit', $started );
		}

		$stale_key = $cache_key . '_stale';
		$lock_key  = $cache_key . '_lock';
		$stale = get_transient( $stale_key );

		if ( get_transient( $lock_key ) ) {
			if ( is_array( $stale ) ) {
				return $this->cache_response( $stale, 'stale', $started, 5 );
			}
			return $this->retry_later_response( $started );
		}

		set_transient( $lock_key, 1, 45 );

		try {
			$payload = call_user_func( $builder );
			$this->remember_rest_payload( $cache_key, $payload );
			delete_transient( $lock_key );
			return $this->cache_response( $payload, 'miss', $started );
		} catch ( Throwable $error ) {
			delete_transient( $lock_key );
			if ( is_array( $stale ) ) {
				return $this->cache_response( $stale, 'stale', $started, 10 );
			}
			return $this->source_unavailable_response( $started, $error->getMessage() );
		}
	}

	private function remember_rest_payload( $cache_key, $payload ) {
		$ttl = absint( $this->option( 'cache_ttl', 600 ) );
		set_transient( $cache_key, $payload, $ttl );
		set_transient( $cache_key . '_stale', $payload, max( 6 * HOUR_IN_SECONDS, $ttl * 12 ) );
	}

	private function cached_fragment( $cache_key, $ttl, $builder, $stale_ttl = 0 ) {
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$lock_key = $cache_key . '_lock';
		$stale_key = $cache_key . '_stale';
		$stale = get_transient( $stale_key );
		if ( get_transient( $lock_key ) && false !== $stale ) {
			return $stale;
		}

		set_transient( $lock_key, 1, 45 );
		try {
			$value = call_user_func( $builder );
			delete_transient( $lock_key );
			if ( false !== $value && null !== $value ) {
				set_transient( $cache_key, $value, max( 1, absint( $ttl ) ) );
				set_transient( $stale_key, $value, max( absint( $stale_ttl ), absint( $ttl ) * 12, HOUR_IN_SECONDS ) );
			}
			return $value;
		} catch ( Throwable $error ) {
			delete_transient( $lock_key );
			if ( false !== $stale ) {
				return $stale;
			}
			throw $error;
		}
	}

	private function cache_response( $payload, $cache_status, $started, $retry_after = 0 ) {
		$response = rest_ensure_response( $payload );
		$response->header( 'X-STW-Cache', $cache_status );
		$response->header( 'X-STW-Elapsed', (string) round( microtime( true ) - $started, 3 ) );
		if ( $retry_after > 0 ) {
			$response->header( 'Retry-After', (string) absint( $retry_after ) );
		}
		return $response;
	}

	private function retry_later_response( $started ) {
		$response = new WP_REST_Response(
			array(
				'code'    => 'stw_dashboard_warming',
				'message' => __( 'Dashboard stats are being prepared. Please retry shortly.', 'stw-dashboard-mailing-stats' ),
				'data'    => array( 'status' => 429 ),
			),
			429
		);
		$response->header( 'Retry-After', '5' );
		$response->header( 'X-STW-Cache', 'warming' );
		$response->header( 'X-STW-Elapsed', (string) round( microtime( true ) - $started, 3 ) );
		return $response;
	}

	private function source_unavailable_response( $started, $message ) {
		$response = new WP_REST_Response(
			array(
				'code'    => 'stw_dashboard_source_unavailable',
				'message' => $message ? sanitize_text_field( $message ) : __( 'Dashboard source data is unavailable.', 'stw-dashboard-mailing-stats' ),
				'data'    => array( 'status' => 503 ),
			),
			503
		);
		$response->header( 'Retry-After', '10' );
		$response->header( 'X-STW-Cache', 'error' );
		$response->header( 'X-STW-Elapsed', (string) round( microtime( true ) - $started, 3 ) );
		return $response;
	}
}
