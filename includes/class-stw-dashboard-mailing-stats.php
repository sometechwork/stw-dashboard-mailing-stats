<?php
/**
 * Main plugin class for the dashboard stats gateway.
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class STW_Dashboard_Mailing_Stats {
	use STW_Dashboard_Mailing_Stats_Admin;
	use STW_Dashboard_Mailing_Stats_REST;
	use STW_Dashboard_Mailing_Stats_Editorial;
	use STW_Dashboard_Mailing_Stats_MailPoet;
	use STW_Dashboard_Mailing_Stats_Rasa;
	use STW_Dashboard_Mailing_Stats_Advanced_Ads;
	use STW_Dashboard_Mailing_Stats_Utilities;

	const OPTION_NAME    = 'stw_dashboard_mailing_stats_options';
	const REST_NAMESPACE = 'stw-dashboard/v1';
	const CACHE_VERSION  = '2026-08-05-performance-cache-v1';

	/**
	 * Debug context collected while reading rasa.io API data.
	 *
	 * @var array<string, mixed>
	 */
	private $rasa_debug = array();

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'admin_menu' ) );
		add_action( 'admin_init', array( $instance, 'register_settings' ) );
		add_action( 'rest_api_init', array( $instance, 'register_routes' ) );
	}

	/**
	 * Ensure default options exist on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		$options = get_option( self::OPTION_NAME );
		if ( ! is_array( $options ) ) {
			add_option(
				self::OPTION_NAME,
				array(
					'dashboard_api_key_hash' => '',
					'rasa_username'          => '',
					'rasa_password'          => '',
					'rasa_api_key'           => '',
					'rasa_base_url'          => 'https://api.rasa.io/v1',
					'cache_ttl'              => 600,
				),
				'',
				false
			);
		}
	}
}
