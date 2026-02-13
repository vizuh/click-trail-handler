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

// Delete plugin options
delete_option( 'clicutcl_attribution_settings' );
delete_option( 'clicutcl_consent_mode' );
delete_option( 'clicutcl_gtm' );
delete_option( 'clicutcl_pii_risk_detected' );
delete_option( 'clicutcl_server_side' );
delete_option( 'clicutcl_last_error' );
delete_option( 'clicutcl_attempts' );
delete_option( 'clicutcl_dispatch_log' );
delete_option( '_transient_clicutcl_debug_until' );
delete_option( '_transient_timeout_clicutcl_debug_until' );

if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	delete_site_option( 'clicutcl_server_side_network' );
}

// Clear scheduled queue processing.
wp_clear_scheduled_hook( 'clicutcl_dispatch_queue' );

// Drop queue table.
global $wpdb;
$queue_table = $wpdb->prefix . 'clicutcl_queue';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$queue_table}" );

// Note: We do NOT delete the 'clicutcl_wa_click' post type data by default,
// as users may want to keep their historical click data even if they uninstall the plugin.
// If complete data removal is required, a specific "Delete Data on Uninstall" setting should be added.
