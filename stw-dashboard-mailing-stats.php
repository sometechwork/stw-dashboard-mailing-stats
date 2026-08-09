<?php
/**
 * Plugin Name: STW Dashboard Stats Gateway
 * Description: Exposes WordPress editorial, MailPoet Premium, rasa.io, and Advanced Ads statistics for the publisher analytics dashboard.
 * Version: 0.1.0
 * Author: STW
 * Text Domain: stw-dashboard-mailing-stats
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stw_dashboard_stats_gateway_files = array(
	'includes/trait-admin.php',
	'includes/trait-rest.php',
	'includes/trait-editorial.php',
	'includes/trait-mailpoet.php',
	'includes/trait-rasa.php',
	'includes/trait-advanced-ads.php',
	'includes/trait-utilities.php',
	'includes/class-stw-dashboard-mailing-stats.php',
);

foreach ( $stw_dashboard_stats_gateway_files as $stw_dashboard_stats_gateway_file ) {
	require_once plugin_dir_path( __FILE__ ) . $stw_dashboard_stats_gateway_file;
}

register_activation_hook( __FILE__, array( 'STW_Dashboard_Mailing_Stats', 'activate' ) );
add_action( 'plugins_loaded', array( 'STW_Dashboard_Mailing_Stats', 'init' ) );
