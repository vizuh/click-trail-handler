<?php
/**
 * Plugin Name: ClickTrail
 * Plugin URI:  https://vizuh.com
 * Description: Captures marketing parameters (UTMs, Click IDs), persists them, and handles basic consent management.
 * Version:     1.0.0-beta
 * Author:      Vizuh
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Source:      https://github.com/vizuh/click-trail
 * Text Domain: click-trail-main
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
define( 'CLICKTRAIL_PII_NONCE_ACTION', 'clicktrail_pii_nonce' );

// Include Core Class
require_once CLICKTRAIL_DIR . 'includes/class-clicktrail-core.php';
require_once CLICKTRAIL_DIR . 'includes/clicktrail-attribution-functions.php';

/**
 * Initialize the plugin
 */
function clicktrail_init() {
	$plugin = new ClickTrail_Core();
	$plugin->run();
}
add_action( 'plugins_loaded', 'clicktrail_init' );
