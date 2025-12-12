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
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			event_data longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_type (event_type)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
