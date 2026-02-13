<?php
/**
 * Retry Queue
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Queue
 */
class Queue {
	/**
	 * Cron hook.
	 */
	const CRON_HOOK = 'clicutcl_dispatch_queue';

	/**
	 * Custom schedule key.
	 */
	const CRON_SCHEDULE = 'clicutcl_five_minutes';

	/**
	 * Max retry attempts.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'process' ) );
		self::ensure_schedule();
	}

	/**
	 * Register custom cron schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function register_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 Minutes', 'click-trail-handler' ),
			);
		}

		return $schedules;
	}

	/**
	 * Ensure cron is scheduled.
	 *
	 * @return void
	 */
	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$schedules = wp_get_schedules();
			$interval  = isset( $schedules[ self::CRON_SCHEDULE ] ) ? self::CRON_SCHEDULE : 'hourly';
			wp_schedule_event( time() + 300, $interval, self::CRON_HOOK );
		}
	}

	/**
	 * Clear scheduled cron.
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Enqueue a failed event for retry.
	 *
	 * @param Event  $event Event.
	 * @param string $adapter_key Adapter key.
	 * @param string $endpoint Endpoint URL.
	 * @param string $error_message Error message.
	 * @return void
	 */
	public static function enqueue( Event $event, $adapter_key, $endpoint, $error_message = '' ) {
		global $wpdb;

		$data = $event->to_array();
		$event_name = isset( $data['event_name'] ) ? sanitize_text_field( (string) $data['event_name'] ) : '';
		$event_id   = isset( $data['event_id'] ) ? sanitize_text_field( (string) $data['event_id'] ) : '';

		if ( ! $event_name || ! $event_id ) {
			return;
		}

		if ( ! self::table_exists() ) {
			self::ensure_table();
		}

		$table_name = self::get_table_name();

		// Avoid duplicates.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE event_name = %s AND event_id = %s LIMIT 1",
				$event_name,
				$event_id
			)
		);
		if ( $existing ) {
			return;
		}

		$next_attempt = gmdate( 'Y-m-d H:i:s', time() + self::get_backoff_seconds( 0 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table_name,
			array(
				'event_name'      => $event_name,
				'event_id'        => $event_id,
				'adapter'         => sanitize_key( (string) $adapter_key ),
				'endpoint'        => esc_url_raw( (string) $endpoint ),
				'payload'         => wp_json_encode( $data ),
				'attempts'        => 0,
				'next_attempt_at' => $next_attempt,
				'last_error'      => sanitize_text_field( (string) $error_message ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Process queued events.
	 *
	 * @return void
	 */
	public static function process() {
		global $wpdb;

		if ( ! Dispatcher::is_enabled() ) {
			return;
		}

		if ( ! self::acquire_lock() ) {
			return;
		}

		$table_name = self::get_table_name();
		if ( ! self::table_exists() ) {
			self::release_lock();
			return;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT 10",
			$now
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $query, ARRAY_A );

		foreach ( $rows as $row ) {
			self::process_row( $row );
		}

		self::release_lock();
	}

	/**
	 * Process single queue row.
	 *
	 * @param array $row Row data.
	 * @return void
	 */
	private static function process_row( $row ) {
		global $wpdb;

		$payload = isset( $row['payload'] ) ? json_decode( (string) $row['payload'], true ) : null;
		if ( ! is_array( $payload ) ) {
			self::delete_row( (int) $row['id'] );
			return;
		}

		$event = new Event( $payload );

		$endpoint = isset( $row['endpoint'] ) ? esc_url_raw( (string) $row['endpoint'] ) : '';
		if ( ! $endpoint ) {
			$endpoint = Dispatcher::get_endpoint();
		}
		if ( ! $endpoint ) {
			return;
		}

		$adapter_key = isset( $row['adapter'] ) ? sanitize_key( (string) $row['adapter'] ) : '';
		$timeout     = Dispatcher::get_timeout();
		$adapter     = Dispatcher::build_adapter( $adapter_key, $endpoint, $timeout );

		if ( ! $adapter ) {
			self::update_row_failure( $row, 'missing_adapter' );
			return;
		}

		$result = $adapter->send( $event );
		Dispatcher::log_dispatch( $event, $adapter, $result );

		if ( $result->success ) {
			self::delete_row( (int) $row['id'] );
			return;
		}

		self::update_row_failure( $row, $result->message );
	}

	/**
	 * Update row after failed attempt.
	 *
	 * @param array  $row Row data.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function update_row_failure( $row, $message ) {
		global $wpdb;

		$attempts = isset( $row['attempts'] ) ? absint( $row['attempts'] ) + 1 : 1;

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::delete_row( (int) $row['id'] );
			Dispatcher::record_last_error( 'queue_failed', $message );
			return;
		}

		$next_attempt = gmdate( 'Y-m-d H:i:s', time() + self::get_backoff_seconds( $attempts ) );
		$table_name   = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update(
			$table_name,
			array(
				'attempts'        => $attempts,
				'next_attempt_at' => $next_attempt,
				'last_error'      => sanitize_text_field( (string) $message ),
			),
			array( 'id' => (int) $row['id'] ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete queue row.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	private static function delete_row( $id ) {
		global $wpdb;

		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $table_name, array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/**
	 * Check if queue table exists.
	 *
	 * @return bool
	 */
	private static function table_exists() {
		global $wpdb;

		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	/**
	 * Ensure queue table exists.
	 *
	 * @return void
	 */
	private static function ensure_table() {
		if ( ! class_exists( 'CLICUTCL\\Database\\Installer' ) ) {
			return;
		}

		\CLICUTCL\Database\Installer::run();
	}

	/**
	 * Get queue table name.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'clicutcl_queue';
	}

	/**
	 * Acquire a short-lived lock to prevent concurrent runs.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		$lock_key = 'clicutcl_queue_lock';
		if ( get_transient( $lock_key ) ) {
			return false;
		}
		set_transient( $lock_key, 1, 60 );
		return true;
	}

	/**
	 * Release the processing lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_transient( 'clicutcl_queue_lock' );
	}

	/**
	 * Calculate retry backoff in seconds.
	 *
	 * @param int $attempt Attempt count.
	 * @return int
	 */
	private static function get_backoff_seconds( $attempt ) {
		$attempt = max( 0, absint( $attempt ) );
		$delay   = (int) min( 3600, 60 * pow( 2, $attempt ) );
		return max( 60, $delay );
	}
}
