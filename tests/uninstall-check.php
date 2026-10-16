<?php
/**
 * Uninstall cleans every site of a multisite network, not only the main site.
 * Runs in its own PHP process (see tests/unit/UninstallTest.php).
 *
 * @package ClickTrail
 */

define( 'WP_UNINSTALL_PLUGIN', 'click-trail-handler/clicutcl.php' );
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['blog']    = 1;
$GLOBALS['dropped'] = array();
$GLOBALS['deleted'] = array();

function apply_filters( $tag, $value ) { return $value; }
function is_multisite() { return true; }
function get_sites( $args ) { return array( 1, 2, 3 ); }
function switch_to_blog( $id ) { $GLOBALS['blog'] = $id; $GLOBALS['wpdb']->prefix = 1 === $id ? 'wp_' : "wp_{$id}_"; }
function restore_current_blog() { switch_to_blog( 1 ); }
function delete_option( $key ) { $GLOBALS['deleted'][ $GLOBALS['blog'] ][] = $key; return true; }
function delete_site_option( $key ) { return true; }
function wp_clear_scheduled_hook( $hook ) { return 0; }

$wpdb = new class() {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public function prepare( $query, ...$args ) { return $query; }
	public function query( $query ) {
		if ( preg_match( '/DROP TABLE IF EXISTS (\S+)/', $query, $m ) ) {
			$GLOBALS['dropped'][] = $m[1];
		}
		return 1;
	}
};

require dirname( __DIR__ ) . '/uninstall.php';

$failures = array();
foreach ( array( 'wp_', 'wp_2_', 'wp_3_' ) as $prefix ) {
	foreach ( array( 'clicutcl_queue', 'clicutcl_events', 'clicutcl_touch_events' ) as $table ) {
		if ( ! in_array( $prefix . $table, $GLOBALS['dropped'], true ) ) {
			$failures[] = "table {$prefix}{$table} not dropped";
		}
	}
}
foreach ( array( 1, 2, 3 ) as $site ) {
	foreach ( array( 'clicutcl_tracking_v2', 'clicutcl_setup_complete', 'clicutcl_db_version' ) as $option ) {
		if ( ! in_array( $option, $GLOBALS['deleted'][ $site ] ?? array(), true ) ) {
			$failures[] = "option {$option} not deleted on site {$site}";
		}
	}
}

if ( $failures ) {
	echo implode( "\n", $failures ), "\n";
	exit( 1 );
}
echo "uninstall checks passed\n";
