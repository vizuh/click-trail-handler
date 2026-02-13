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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned and escaped.
		$sql = "DELETE FROM {$table_name_escaped} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT 1000";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron cleanup on plugin-owned table.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query string is constructed safely above.
			$wpdb->prepare( $sql, $days )
		);

		$queue_days = (int) apply_filters( 'clicutcl_queue_retention_days', 7 );
		if ( $queue_days < 1 ) {
			$queue_days = 7;
		}

		$queue_table = $wpdb->prefix . 'clicutcl_queue';
		$queue_table_escaped = esc_sql( $queue_table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight metadata check on plugin-owned table; no core wrapper available.
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $queue_table ) ) !== $queue_table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned and escaped.
		$queue_sql = "DELETE FROM {$queue_table_escaped} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT 1000";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron cleanup on plugin-owned table.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query string is constructed safely above.
			$wpdb->prepare( $queue_sql, $queue_days )
		);
	}
}
