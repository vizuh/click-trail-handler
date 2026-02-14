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
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY event_name_event_id (event_name, event_id),
			KEY next_attempt_at (next_attempt_at)
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
}
