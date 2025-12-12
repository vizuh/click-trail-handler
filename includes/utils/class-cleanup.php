<?php
/**
 * Cleanup Utilities
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Utils;

use CLICUTCL\Settings\Attribution_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cleanup
 */
class Cleanup {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'clicutcl_daily_cleanup', array( $this, 'run_cleanup' ) );
	}

	/**
	 * Run the cleanup routine.
	 */
	public function run_cleanup() {
		global $wpdb;

		$settings = new Attribution_Settings();
		$days     = $settings->get_cookie_duration(); // Use cookie duration as retention period, or default to 90.
		
		if ( $days < 1 ) {
			$days = 90;
		}

		$table_name = $wpdb->prefix . 'clicutcl_events';
		$table_name_escaped = esc_sql( $table_name ); // Internal, but still escape.

		// Safety check: Ensure table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight metadata check on plugin-owned table; no core wrapper available.
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron cleanup on plugin-owned table; values are prepared and query runs infrequently.
		$sql = $wpdb->prepare(
			"DELETE FROM {$table_name_escaped} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		);

		$wpdb->query( $sql );
	}
}
