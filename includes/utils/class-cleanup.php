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

		// Safety check: Ensure table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}
}
