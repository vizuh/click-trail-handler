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
	public const DB_VERSION = 2;

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

		self::create_tables();
	}

	/**
	 * Create database tables.
	 */
	private static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'clicutcl_events';
		$queue_table     = $wpdb->prefix . 'clicutcl_queue';
		$charset_collate = $wpdb->get_charset_collate();

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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $queue_sql );

		$events_ready = self::table_exists( $table_name );
		$queue_ready  = self::table_exists( $queue_table );
		$checked_at   = time();

		update_option( self::EVENTS_READY_OPTION, $events_ready ? 1 : 0, false );
		update_option( self::EVENTS_READY_CHECKED_AT_OPTION, $checked_at, false );
		update_option( self::QUEUE_READY_OPTION, $queue_ready ? 1 : 0, false );
		update_option( self::QUEUE_READY_CHECKED_AT_OPTION, $checked_at, false );

		// Backward-compatible aggregate readiness flags.
		update_option( 'clicutcl_db_ready', ( $events_ready && $queue_ready ) ? 1 : 0, false );
		update_option( 'clicutcl_db_ready_checked_at', $checked_at, false );

		// Record schema version only when the queue status column verifiably
		// exists, so maybe_upgrade() retries on the next request if dbDelta failed.
		if ( $queue_ready && self::column_exists( $queue_table, 'status' ) ) {
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
}
