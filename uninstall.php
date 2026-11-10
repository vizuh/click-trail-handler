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
$clicutcl_preserve_data = (bool) apply_filters( 'clicutcl_preserve_data_on_uninstall', false );

// WordPress may include uninstall.php without loading the plugin bootstrap, so load the helpers that
// own the option names (`Installer::UNINSTALL_OPTION_NAMES`) and the claim-row name patterns.
require_once __DIR__ . '/includes/database/class-installer.php';
require_once __DIR__ . '/includes/tracking/class-claim-lock.php';

/**
 * Remove the current site's ClickTrail options, scheduled hooks, transients, claim rows and tables.
 *
 * Each site of a network has its own options table, cron and plugin tables, so this runs once per site.
 */
$clicutcl_uninstall_site = static function () use ( $clicutcl_preserve_data ) {
	global $wpdb;

	// Delete plugin options only when data preservation was not requested.
	if ( ! $clicutcl_preserve_data ) {
		foreach ( \CLICUTCL\Database\Installer::UNINSTALL_OPTION_NAMES as $option_name ) {
			delete_option( $option_name );
		}
	}

	// Clear scheduled hooks.
	wp_clear_scheduled_hook( 'clicutcl_dispatch_queue' );
	wp_clear_scheduled_hook( 'clicutcl_daily_cleanup' );

	// Cancel Action Scheduler recurring actions when AS library is present.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'clicutcl_dispatch_queue', array(), 'clicktrail-delivery' );
	}

	if ( $clicutcl_preserve_data ) {
		return;
	}

	// Clear known transients used for diagnostics, dedup, rate limits, and replay guards.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup; no cache needed.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_clicutcl_%',
			'_transient_timeout_clicutcl_%'
		)
	);

	// Without a persistent object cache, purchase, sign-up and form claims are options rows
	// (`clicutcl_*_lock_*`, `clicutcl_form_claim_*`).
	\CLICUTCL\Tracking\Claim_Lock::delete_all_rows();

	$queue_table         = $wpdb->prefix . 'clicutcl_queue';
	$events_table        = $wpdb->prefix . 'clicutcl_events';
	$touch_events_table  = $wpdb->prefix . 'clicutcl_touch_events';
	$whatsapp_refs_table = $wpdb->prefix . 'clicutcl_whatsapp_refs';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup.
	$wpdb->query( "DROP TABLE IF EXISTS {$queue_table}" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup.
	$wpdb->query( "DROP TABLE IF EXISTS {$events_table}" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup.
	$wpdb->query( "DROP TABLE IF EXISTS {$touch_events_table}" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup.
	$wpdb->query( "DROP TABLE IF EXISTS {$whatsapp_refs_table}" );
};

if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	// 'number' => 0 lifts get_sites()' default cap of 100 sites.
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $clicutcl_site_id ) {
		switch_to_blog( (int) $clicutcl_site_id );
		$clicutcl_uninstall_site();
		restore_current_blog();
	}

	if ( ! $clicutcl_preserve_data ) {
		delete_site_option( 'clicutcl_server_side_network' );
	}
} else {
	$clicutcl_uninstall_site();
}

if ( ! $clicutcl_preserve_data ) {
	// Only uninstall also removes the purchase-sent marker (subject erase, retention and the Diagnostics purge keep it).
	// Load the lifecycle helper because WordPress may include uninstall.php
	// without loading the plugin bootstrap first.
	$clicutcl_woo_privacy_file = __DIR__ . '/includes/privacy/class-woo-order-privacy.php';
	if ( function_exists( 'wc_get_orders' ) && file_exists( $clicutcl_woo_privacy_file ) ) {
		require_once $clicutcl_woo_privacy_file;
		$clicutcl_woo_purge = ( new \CLICUTCL\Privacy\Woo_Order_Privacy() )->purge_all_order_metadata( \CLICUTCL\Privacy\Woo_Order_Privacy::RETENTION_BATCH_SIZE, true );
		if ( ! empty( $clicutcl_woo_purge['remaining'] ) && function_exists( 'do_action' ) ) {
			do_action( 'clicutcl_uninstall_order_meta_incomplete', $clicutcl_woo_purge );
		}
	}

	// EDD 3.x orders carry the same ClickTrail meta keys; the EDD helper reuses the Woo key allowlist.
	// Only uninstall also removes the purchase-sent marker (subject erase, retention and the Diagnostics purge keep it).
	$clicutcl_edd_privacy_file = __DIR__ . '/includes/privacy/class-edd-order-privacy.php';
	if ( function_exists( 'edd_get_orders' ) && file_exists( $clicutcl_woo_privacy_file ) && file_exists( $clicutcl_edd_privacy_file ) ) {
		require_once $clicutcl_woo_privacy_file;
		require_once $clicutcl_edd_privacy_file;
		$clicutcl_edd_purge = ( new \CLICUTCL\Privacy\Edd_Order_Privacy() )->purge_all_order_metadata( \CLICUTCL\Privacy\Edd_Order_Privacy::RETENTION_BATCH_SIZE, true );
		if ( ! empty( $clicutcl_edd_purge['remaining'] ) && function_exists( 'do_action' ) ) {
			do_action( 'clicutcl_uninstall_order_meta_incomplete', $clicutcl_edd_purge );
		}
	}

	// Registration attribution meta (Ultimate Member) lives in user meta, which a network shares.
	// Load the helper because WordPress may include uninstall.php without loading the plugin bootstrap first.
	if ( function_exists( 'get_users' ) ) {
		require_once __DIR__ . '/includes/Core/class-attribution-provider.php';
		require_once __DIR__ . '/includes/integrations/class-ultimate-member.php';
		require_once __DIR__ . '/includes/privacy/class-user-registration-privacy.php';
		$clicutcl_user_purge = ( new \CLICUTCL\Privacy\User_Registration_Privacy() )->purge_all_user_metadata();
		if ( ! empty( $clicutcl_user_purge['remaining'] ) && function_exists( 'do_action' ) ) {
			do_action( 'clicutcl_uninstall_user_meta_incomplete', $clicutcl_user_purge );
		}
	}
}
