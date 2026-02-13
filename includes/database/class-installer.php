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
	}
}
