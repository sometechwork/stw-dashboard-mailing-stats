<?php
/**
 * Uninstall cleanup for STW Dashboard Mailing Stats.
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'stw_dashboard_mailing_stats_options' );

global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_stw_dashboard_mailing_' ) . '%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_stw_dashboard_mailing_' ) . '%'
	)
);
