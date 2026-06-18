<?php
/**
 * Retry Queue
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

use CLICUTCL\Tracking\Dedup_Store;

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
	 * Action Scheduler group.
	 */
	const AS_GROUP = 'clicktrail-delivery';

	/**
	 * Max retry attempts.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Processing lock TTL in seconds. Must comfortably exceed a worst-case
	 * batch (10 sends at the default 5s timeout plus overhead).
	 */
	const LOCK_TTL = 120;

	/**
	 * Lock option name (options table is used for an atomic INSERT-based lock;
	 * transients are get-then-set and allow concurrent crons to double-send).
	 */
	private const LOCK_OPTION = 'clicutcl_queue_lock';

	/**
	 * DB readiness option key.
	 */
	private const DB_READY_OPTION = 'clicutcl_queue_table_ready';

	/**
	 * DB readiness checked timestamp option key.
	 */
	private const DB_READY_CHECKED_AT_OPTION = 'clicutcl_queue_table_checked_at';

	/**
	 * In-request memoized table readiness.
	 *
	 * @var bool|null
	 */
	private static $table_exists_mem = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- private static class property, not a global variable.

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Sub-15-minute interval is required for timely server-side event queue dispatch.
		add_action( self::CRON_HOOK, array( __CLASS__, 'process' ) );
		if ( class_exists( 'CLICUTCL\\Database\\Installer' ) ) {
			\CLICUTCL\Database\Installer::maybe_upgrade();
		}
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
	 * Uses Action Scheduler when available (WooCommerce / AS library present),
	 * otherwise falls back to WP-cron. Both fire the same CRON_HOOK action so
	 * the rest of the class requires no further changes.
	 *
	 * @return void
	 */
	public static function ensure_schedule() {
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( self::CRON_HOOK, array(), self::AS_GROUP ) ) {
				as_schedule_recurring_action( time() + 300, 300, self::CRON_HOOK, array(), self::AS_GROUP );
			}

			// Clear any WP-cron event scheduled before Action Scheduler became
			// available; otherwise both schedulers fire the hook every 5 minutes.
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}

		// WP-cron fallback.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$schedules = wp_get_schedules();
			$interval  = isset( $schedules[ self::CRON_SCHEDULE ] ) ? self::CRON_SCHEDULE : 'hourly';
			wp_schedule_event( time() + 300, $interval, self::CRON_HOOK );
		}
	}

	/**
	 * Clear scheduled cron.
	 *
	 * Cancels the Action Scheduler recurring action when AS is available, and
	 * always clears the WP-cron hook so no orphaned entries remain after a
	 * scheduler switch.
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, array(), self::AS_GROUP );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Enqueue a failed event for retry.
	 *
	 * @param Event  $event Event.
	 * @param string $adapter_key Adapter key.
	 * @param string $endpoint Endpoint URL.
	 * @param string $error_message Error message.
	 * @return bool True when the event is already queued or has been persisted for retry.
	 */
	public static function enqueue( Event $event, $adapter_key, $endpoint, $error_message = '' ) {
		global $wpdb;

		$data       = $event->to_array();
		$event_name = isset( $data['event_name'] ) ? sanitize_text_field( (string) $data['event_name'] ) : '';
		$event_id   = isset( $data['event_id'] ) ? sanitize_text_field( (string) $data['event_id'] ) : '';

		if ( ! $event_name || ! $event_id ) {
			return false;
		}

		if ( ! self::table_exists() ) {
			self::ensure_table();
		}

		if ( ! self::table_exists() ) {
			return false;
		}

		$table_name = self::get_table_name();

		// Avoid duplicates. A dead-letter row for the same event is revived for
		// a fresh round of attempts instead of silently blocking the retry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE event_name = %s AND event_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
				$event_name,
				$event_id
			),
			ARRAY_A
		);
		if ( is_array( $existing ) ) {
			if ( self::db_supports_status() && isset( $existing['status'] ) && 'failed' === $existing['status'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; queue mutation.
				$wpdb->update(
					$table_name,
					array(
						'status'          => 'pending',
						'attempts'        => 0,
						'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::get_backoff_seconds( 0 ) ),
						'last_error'      => sanitize_text_field( (string) $error_message ),
					),
					array( 'id' => (int) $existing['id'] ),
					array( '%s', '%d', '%s', '%s' ),
					array( '%d' )
				);
			}
			return true;
		}

		$next_attempt = gmdate( 'Y-m-d H:i:s', time() + self::get_backoff_seconds( 0 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
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

		return false !== $inserted;
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

		if ( self::db_supports_status() ) {
			$query = $wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE status = 'pending' AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
				$now
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
				$now
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared above; variable passed for readability.
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
			// Count as a failed attempt; a bare return would retry this row on
			// every run without ever exhausting it.
			self::update_row_failure( $row, 'missing_endpoint' );
			return;
		}

		$adapter_key = isset( $row['adapter'] ) ? sanitize_key( (string) $row['adapter'] ) : '';
		$timeout     = Dispatcher::get_timeout();
		$adapter     = Dispatcher::build_adapter( $adapter_key, $endpoint, $timeout );

		if ( ! $adapter ) {
			self::update_row_failure( $row, 'missing_adapter' );
			return;
		}

		$event_payload = $event->to_array();
		$event_name    = isset( $event_payload['event_name'] ) ? sanitize_key( (string) $event_payload['event_name'] ) : '';
		$event_id      = isset( $event_payload['event_id'] ) ? sanitize_text_field( (string) $event_payload['event_id'] ) : '';
		$destination   = method_exists( $adapter, 'get_name' ) ? sanitize_key( (string) $adapter->get_name() ) : $adapter_key;

		if ( $event_name && $event_id && Dedup_Store::is_duplicate( $destination, $event_name, $event_id ) ) {
			self::delete_row( (int) $row['id'] );
			return;
		}

		$result = $adapter->send( $event );
		Dispatcher::log_dispatch( $event, $adapter, $result );

		if ( $result->success ) {
			if ( $event_name && $event_id ) {
				Dedup_Store::mark( $destination, $event_name, $event_id );
			}
			self::delete_row( (int) $row['id'] );
			return;
		}

		self::update_row_failure( $row, $result->message, (bool) $result->retryable );
	}

	/**
	 * Update row after failed attempt.
	 *
	 * Exhausted or terminally rejected rows are kept with status `failed`
	 * (dead-letter) instead of deleted, so an outage longer than the backoff
	 * window no longer destroys conversions unrecoverably. Failed rows are
	 * visible in diagnostics, replayable via `requeue_failed()`, and bounded
	 * by the existing created_at cleanup cron.
	 *
	 * @param array  $row Row data.
	 * @param string $message Error message.
	 * @param bool   $retryable Whether the failure may succeed on retry.
	 * @return void
	 */
	private static function update_row_failure( $row, $message, $retryable = true ) {
		global $wpdb;

		$attempts = isset( $row['attempts'] ) ? absint( $row['attempts'] ) + 1 : 1;
		Dispatcher::record_failure( 'queue_retry_failed' );

		if ( ! $retryable || $attempts >= self::MAX_ATTEMPTS ) {
			self::mark_row_failed( $row, $attempts, $message );
			Dispatcher::record_last_error( 'queue_failed', $message );
			Dispatcher::record_failure( $retryable ? 'queue_dropped' : 'queue_rejected' );
			return;
		}

		$next_attempt = gmdate( 'Y-m-d H:i:s', time() + self::get_backoff_seconds( $attempts ) );
		$table_name   = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; no cache for queue mutation.
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
	 * Move a row to the dead-letter state (or delete on pre-v2 schemas).
	 *
	 * @param array  $row Row data.
	 * @param int    $attempts Attempt count to record.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function mark_row_failed( $row, $attempts, $message ) {
		global $wpdb;

		if ( ! self::db_supports_status() ) {
			self::delete_row( (int) $row['id'] );
			return;
		}

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; no cache for queue mutation.
		$wpdb->update(
			$table_name,
			array(
				'status'     => 'failed',
				'attempts'   => absint( $attempts ),
				'last_error' => sanitize_text_field( (string) $message ),
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Requeue dead-letter rows for another round of attempts.
	 *
	 * @param int $limit Max rows to requeue.
	 * @return int Number of rows requeued.
	 */
	public static function requeue_failed( $limit = 50 ) {
		global $wpdb;

		if ( ! self::db_supports_status() || ! self::table_exists() ) {
			return 0;
		}

		$limit      = max( 1, min( 500, absint( $limit ) ) );
		$table_name = self::get_table_name();
		$now        = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; queue mutation.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name} SET status = 'pending', attempts = 0, next_attempt_at = %s WHERE status = 'failed' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-owned.
				$now,
				$limit
			)
		);

		return (int) $updated;
	}

	/**
	 * Whether the queue table has the v2 `status` column.
	 *
	 * @return bool
	 */
	private static function db_supports_status() {
		if ( ! class_exists( 'CLICUTCL\\Database\\Installer' ) ) {
			return false;
		}

		return (int) get_option( \CLICUTCL\Database\Installer::DB_VERSION_OPTION, 0 ) >= 2;
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; no cache for queue mutation.
		$wpdb->delete( $table_name, array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/**
	 * Check if queue table exists.
	 *
	 * @return bool
	 */
	private static function table_exists() {
		if ( null !== self::$table_exists_mem ) {
			return self::$table_exists_mem;
		}

		$stored = get_option( self::DB_READY_OPTION, null );
		if ( null === $stored ) {
			$stored = get_option( 'clicutcl_db_ready', null );
		}
		$now = time();

		if ( null === $stored ) {
			$ready = self::table_exists_fast();
			self::persist_db_ready( $ready, $now );
			self::$table_exists_mem = $ready;
			return $ready;
		}

		$ready      = 1 === (int) $stored;
		$checked_at = (int) get_option( self::DB_READY_CHECKED_AT_OPTION, 0 );
		if ( ! $checked_at ) {
			$checked_at = (int) get_option( 'clicutcl_db_ready_checked_at', 0 );
		}
		if ( ( $now - $checked_at ) > DAY_IN_SECONDS ) {
			$ready = self::table_exists_fast();
			self::persist_db_ready( $ready, $now );
		}

		self::$table_exists_mem = $ready;
		return $ready;
	}

	/**
	 * Fast queue table existence check.
	 *
	 * @return bool
	 */
	private static function table_exists_fast() {
		global $wpdb;

		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	/**
	 * Persist DB readiness flags.
	 *
	 * @param bool $ready DB readiness.
	 * @param int  $checked_at Checked timestamp.
	 * @return void
	 */
	private static function persist_db_ready( $ready, $checked_at ) {
		update_option( self::DB_READY_OPTION, $ready ? 1 : 0, false );
		update_option( self::DB_READY_CHECKED_AT_OPTION, absint( $checked_at ), false );
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
		self::$table_exists_mem = null;
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
	 * Uses an atomic INSERT into the options table (unique key on
	 * option_name): exactly one of two concurrent crons can win. The previous
	 * transient get-then-set allowed both to pass and double-send events.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomicity requires a direct INSERT; option APIs are get-then-set.
		$acquired = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				(string) time()
			)
		);

		if ( $acquired ) {
			return true;
		}

		// Lock row exists: recover it when stale (holder crashed mid-run).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read paired with the atomic lock insert above.
		$held_at = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::LOCK_OPTION
			)
		);

		if ( $held_at && ( time() - $held_at ) > self::LOCK_TTL ) {
			self::release_lock();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomicity requires a direct INSERT; option APIs are get-then-set.
			$acquired = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::LOCK_OPTION,
					(string) time()
				)
			);
			return (bool) $acquired;
		}

		return false;
	}

	/**
	 * Release the processing lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paired with the atomic lock insert; bypasses the options cache deliberately.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				self::LOCK_OPTION
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
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

	/**
	 * Return queue diagnostics stats.
	 *
	 * @return array
	 */
	public static function get_stats() {
		global $wpdb;

		$stats = array(
			'ready'        => false,
			'pending'      => 0,
			'failed'       => 0,
			'due_now'      => 0,
			'max_attempts' => 0,
			'oldest_next'  => '',
		);

		if ( ! self::table_exists() ) {
			return $stats;
		}

		$table_name = self::get_table_name();
		$now        = current_time( 'mysql', true );

		if ( self::db_supports_status() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is plugin-owned; live stats query.
			$pending = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name} WHERE status = 'pending'" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is plugin-owned; live stats query.
			$stats['failed'] = max( 0, (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name} WHERE status = 'failed'" ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is plugin-owned; live stats query.
			$pending = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name}" );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is plugin-owned; live stats query.
		$max_attempts = (int) $wpdb->get_var( "SELECT MAX(attempts) FROM {$table_name}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is plugin-owned; live stats query.
		$oldest_next = (string) $wpdb->get_var( "SELECT MIN(next_attempt_at) FROM {$table_name}" );

		$due_filter = self::db_supports_status() ? "status = 'pending' AND " : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Live stats query.
		$due_now = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM {$table_name} WHERE {$due_filter}next_attempt_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name plugin-owned; filter is a fixed literal.
				$now
			)
		);

		$stats['ready']        = true;
		$stats['pending']      = max( 0, $pending );
		$stats['due_now']      = max( 0, $due_now );
		$stats['max_attempts'] = max( 0, $max_attempts );
		$stats['oldest_next']  = sanitize_text_field( $oldest_next );

		return $stats;
	}

	/**
	 * Return a queued row for a specific event, if present.
	 *
	 * @param string $event_name Event name.
	 * @param string $event_id Event ID.
	 * @return array<string,mixed>
	 */
	public static function find_event_row( string $event_name, string $event_id ): array {
		global $wpdb;

		$event_name = sanitize_key( $event_name );
		$event_id   = sanitize_text_field( $event_id );
		if ( '' === $event_name || '' === $event_id || ! self::table_exists() ) {
			return array();
		}

		$table_name = esc_sql( self::get_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live queue lookup for diagnostics.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT event_name, event_id, adapter, attempts, next_attempt_at, last_error FROM {$table_name} WHERE event_name = %s AND event_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table.
				$event_name,
				$event_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}
}
