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

		$table_name         = $wpdb->prefix . 'clicutcl_events';
		$table_name_escaped = esc_sql( $table_name ); // Internal, but still escape.

		// Safety check: Ensure table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight metadata check on plugin-owned table; no core wrapper available.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
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

		$queue_table         = $wpdb->prefix . 'clicutcl_queue';
		$queue_table_escaped = esc_sql( $queue_table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight metadata check on plugin-owned table; no core wrapper available.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) !== $queue_table ) {
			return;
		}

		// Dead-letter rows (status = 'failed') are kept longer than ordinary queue rows
		// so they stay replayable via Queue::requeue_failed() instead of being silently
		// purged with the short retention.
		$dead_letter_days = (int) apply_filters( 'clicutcl_queue_dead_letter_retention_days', 30 );
		if ( $dead_letter_days < $queue_days ) {
			$dead_letter_days = $queue_days;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Lightweight metadata check on plugin-owned escaped table.
		$has_status = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$queue_table_escaped} LIKE %s", 'status' ) );

		if ( $has_status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned and escaped.
			$queue_sql = "DELETE FROM {$queue_table_escaped} WHERE ( status <> 'failed' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) ) OR ( status = 'failed' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) ) LIMIT 1000";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron cleanup on plugin-owned table.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query string is constructed safely above.
				$wpdb->prepare( $queue_sql, $queue_days, $dead_letter_days )
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned and escaped.
			$queue_sql = "DELETE FROM {$queue_table_escaped} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT 1000";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron cleanup on plugin-owned table.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query string is constructed safely above.
				$wpdb->prepare( $queue_sql, $queue_days )
			);
		}
	}
}
