<?php
/**
 * Database Installer
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Installer
 */
class Installer {

	/**
	 * Schema version. Bump when table definitions change; `maybe_upgrade()`
	 * re-runs dbDelta on existing installs until the stored version matches.
	 */
	public const DB_VERSION = 4;

	/**
	 * Option key for the installed schema version.
	 */
	public const DB_VERSION_OPTION = 'clicutcl_db_version';

	/**
	 * Events table readiness option key.
	 */
	private const EVENTS_READY_OPTION = 'clicutcl_events_table_ready';

	/**
	 * Events table readiness checked timestamp option key.
	 */
	private const EVENTS_READY_CHECKED_AT_OPTION = 'clicutcl_events_table_checked_at';

	/**
	 * Queue table readiness option key.
	 */
	private const QUEUE_READY_OPTION = 'clicutcl_queue_table_ready';

	/**
	 * Queue table readiness checked timestamp option key.
	 */
	private const QUEUE_READY_CHECKED_AT_OPTION = 'clicutcl_queue_table_checked_at';

	/**
	 * Touch events table readiness option key.
	 */
	private const TOUCH_EVENTS_READY_OPTION = 'clicutcl_touch_events_table_ready';

	/**
	 * Touch events table readiness checked timestamp option key.
	 */
	private const TOUCH_EVENTS_READY_CHECKED_AT_OPTION = 'clicutcl_touch_events_table_checked_at';

	/**
	 * Run the installer.
	 */
	public static function run() {
		self::create_tables();
	}

	/**
	 * Upgrade the schema when the stored version is behind DB_VERSION.
	 *
	 * Cheap option read on the happy path; runs dbDelta only when behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		// Throttle retries: when an upgrade cannot complete (e.g. dbDelta could not add the
		// queue `status` column), DB_VERSION_OPTION is never recorded, so without this guard
		// create_tables() would re-run its 2x dbDelta + probes + option writes on every request.
		if ( get_transient( 'clicutcl_db_upgrade_attempt' ) ) {
			return;
		}
		set_transient( 'clicutcl_db_upgrade_attempt', 1, HOUR_IN_SECONDS );

		self::create_tables();

		// Clear the throttle once the upgrade has completed, so a later legitimate
		// upgrade is not delayed by a stale attempt marker.
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::DB_VERSION ) {
			delete_transient( 'clicutcl_db_upgrade_attempt' );
		}
	}

	/**
	 * Create database tables.
	 */
	private static function create_tables() {
		global $wpdb;

		$table_name         = $wpdb->prefix . 'clicutcl_events';
		$queue_table        = $wpdb->prefix . 'clicutcl_queue';
		$touch_events_table = $wpdb->prefix . 'clicutcl_touch_events';
		$charset_collate    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			event_data longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_type (event_type)
		) $charset_collate;";

		$queue_sql = "CREATE TABLE $queue_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_name varchar(100) NOT NULL,
			event_id varchar(128) NOT NULL,
			adapter varchar(40) NOT NULL,
			endpoint text,
			payload longtext NOT NULL,
			attempts int unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NOT NULL,
			last_error text,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY event_name_event_id (event_name, event_id),
			KEY next_attempt_at (next_attempt_at),
			KEY status (status)
		) $charset_collate;";

		// Structured, queryable touch-event log -- the Pro-reporting foundation.
		// Separate from clicutcl_events (the JSON-blob debug/admin log): both
		// tables coexist permanently, this one is written on every touch event
		// and conversion regardless of server-side delivery configuration.
		$touch_events_sql = "CREATE TABLE $touch_events_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
			visitor_id varchar(64) NOT NULL DEFAULT '',
			session_id varchar(64) NOT NULL DEFAULT '',
			event_name varchar(64) NOT NULL,
			event_id text DEFAULT NULL,
			event_key char(64) DEFAULT NULL,
			funnel_stage varchar(20) NOT NULL DEFAULT 'unknown',
			source_channel varchar(20) NOT NULL DEFAULT 'web',
			touch_source varchar(128) DEFAULT NULL,
			touch_medium varchar(128) DEFAULT NULL,
			touch_campaign varchar(255) DEFAULT NULL,
			ft_source varchar(128) DEFAULT NULL,
			ft_medium varchar(128) DEFAULT NULL,
			ft_campaign varchar(255) DEFAULT NULL,
			order_id bigint(20) unsigned DEFAULT NULL,
			amount decimal(12,4) DEFAULT NULL,
			currency varchar(8) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY blog_event_key (blog_id, event_key),
			KEY visitor_id (visitor_id),
			KEY order_id (order_id),
			KEY blog_id (blog_id),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $queue_sql );
		dbDelta( $touch_events_sql );

		$events_ready       = self::table_exists( $table_name );
		$queue_ready        = self::table_exists( $queue_table );
		$touch_events_ready = self::table_exists( $touch_events_table )
			&& self::column_exists( $touch_events_table, 'event_id' )
			&& self::column_exists( $touch_events_table, 'event_key' )
			&& self::has_touch_event_unique_key( $touch_events_table );
		$checked_at         = time();

		update_option( self::EVENTS_READY_OPTION, $events_ready ? 1 : 0, false );
		update_option( self::EVENTS_READY_CHECKED_AT_OPTION, $checked_at, false );
		update_option( self::QUEUE_READY_OPTION, $queue_ready ? 1 : 0, false );
		update_option( self::QUEUE_READY_CHECKED_AT_OPTION, $checked_at, false );
		update_option( self::TOUCH_EVENTS_READY_OPTION, $touch_events_ready ? 1 : 0, false );
		update_option( self::TOUCH_EVENTS_READY_CHECKED_AT_OPTION, $checked_at, false );

		// Backward-compatible aggregate readiness flags.
		update_option( 'clicutcl_db_ready', ( $events_ready && $queue_ready && $touch_events_ready ) ? 1 : 0, false );
		update_option( 'clicutcl_db_ready_checked_at', $checked_at, false );

		// Record schema version only when the queue status column verifiably
		// exists and the touch events table is up, so maybe_upgrade() retries
		// on the next request if dbDelta failed to fully apply.
		if ( $queue_ready && self::column_exists( $queue_table, 'status' ) && $touch_events_ready ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}

		// Seed tracking v2 option surfaces once (feature flags, destinations, lifecycle, external providers).
		if ( class_exists( 'CLICUTCL\\Tracking\\Settings' ) ) {
			$option_name = \CLICUTCL\Tracking\Settings::OPTION;
			$existing    = get_option( $option_name, null );
			if ( null === $existing ) {
				update_option( $option_name, \CLICUTCL\Tracking\Settings::defaults(), false );
			}
		}
	}

	/**
	 * Fast table existence check.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return is_string( $found ) && $found === $table_name;
	}

	/**
	 * Column existence check.
	 *
	 * @param string $table_name Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function column_exists( $table_name, $column ) {
		global $wpdb;

		$table_name = esc_sql( $table_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name, escaped above.
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table_name} LIKE %s", $column ) );
		return is_string( $found ) && $found === $column;
	}

	/**
	 * Verify the complete unique key before declaring the migration ready.
	 *
	 * @param string $table_name Plugin-owned touch table.
	 * @return bool
	 */
	private static function has_touch_event_unique_key( string $table_name ): bool {
		global $wpdb;
		$table_name = esc_sql( $table_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name, escaped above.
		$index = $wpdb->get_results( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'blog_event_key'", ARRAY_A );
		return is_array( $index ) && count( $index ) === 2
			&& array_column( $index, 'Column_name' ) === array( 'blog_id', 'event_key' )
			&& array_sum( array_column( $index, 'Non_unique' ) ) === 0
			&& ! array_filter( array_column( $index, 'Sub_part' ) );
	}
}
