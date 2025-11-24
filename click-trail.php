<?php
/**
 * Plugin Name: ClickTrail
 * Plugin URI:  https://vizuh.com
 * Description: Captures marketing parameters (UTMs, Click IDs), persists them, and handles basic consent management.
 * Version:     1.0.0-beta
 * Author:      Vizuh
 * Text Domain: clicktrail
 * Domain Path: /languages
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'CLICKTRAIL_VERSION', '1.0.0-beta' );
define( 'CLICKTRAIL_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLICKTRAIL_URL', plugin_dir_url( __FILE__ ) );
define( 'CLICKTRAIL_BASENAME', plugin_basename( __FILE__ ) );

// Include Core Class
require_once CLICKTRAIL_DIR . 'includes/class-hp-attribution-core.php';
require_once CLICKTRAIL_DIR . 'includes/hp-attribution-functions.php';

/**
 * Initialize the plugin
 */
function clicktrail_init() {
	$plugin = new ClickTrail_Core();
	$plugin->run();
}
add_action( 'plugins_loaded', 'clicktrail_init' );
