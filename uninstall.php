<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package ClickTrail
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Allow hosts to preserve data on uninstall when needed.
 *
 * @param bool $preserve_data Whether to keep ClickTrail data.
 */
$preserve_data = (bool) apply_filters( 'clicutcl_preserve_data_on_uninstall', false );

// Delete plugin options.
$option_keys = array(
	'clicutcl_attribution_settings',
	'clicutcl_consent_mode',
	'clicutcl_gtm',
	'clicutcl_pii_risk_detected',
	'clicutcl_server_side',
	'clicutcl_server_side_network',
	'clicutcl_tracking_v2',
	'clicutcl_last_error',
	'clicutcl_attempts',
	'clicutcl_dispatch_log',
	'clicutcl_sitehealth_status',
	'clicutcl_db_ready',
	'clicutcl_db_ready_checked_at',
	'clicutcl_events_table_ready',
	'clicutcl_events_table_checked_at',
	'clicutcl_queue_table_ready',
	'clicutcl_queue_table_checked_at',
	'_transient_clicutcl_debug_until',
	'_transient_timeout_clicutcl_debug_until',
);
foreach ( $option_keys as $option_key ) {
	delete_option( $option_key );
}

if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	delete_site_option( 'clicutcl_server_side_network' );
}

// Clear scheduled hooks.
wp_clear_scheduled_hook( 'clicutcl_dispatch_queue' );
wp_clear_scheduled_hook( 'clicutcl_daily_cleanup' );

// Clear known transients used for diagnostics, dedup, rate limits, and replay guards.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_clicutcl_%',
		'_transient_timeout_clicutcl_%'
	)
);

if ( ! $preserve_data ) {
	$queue_table  = $wpdb->prefix . 'clicutcl_queue';
	$events_table = $wpdb->prefix . 'clicutcl_events';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$queue_table}" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$events_table}" );
}
