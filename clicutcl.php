<?php
/**
 * Plugin Name: ClickTrail – UTM, Click ID & Ad Tracking (with Consent)
 * Plugin URI:  https://github.com/vizuh/click-trail-handler
 * Description: Complete consent management and marketing attribution solution. Captures UTM parameters and click IDs, manages user consent with Google Consent Mode, and tracks attribution across forms, WooCommerce, and WhatsApp.
 * Version:     1.1.0
 * Author:      Vizuh
 * Author URI:  https://vizuh.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Source:      https://github.com/vizuh/click-trail
 * Text Domain: click-trail-handler
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP:      7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'CLICUTCL_VERSION', '1.1.0' );
define( 'CLICUTCL_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLICUTCL_URL', plugin_dir_url( __FILE__ ) );
define( 'CLICUTCL_BASENAME', plugin_basename( __FILE__ ) );
define( 'CLICUTCL_PLUGIN_MAIN_FILE', __FILE__ );
define( 'CLICUTCL_PII_NONCE_ACTION', 'clicutcl_pii_nonce' );



// Include Core Class
require_once CLICUTCL_DIR . 'includes/class-clicutcl-core.php';
require_once CLICUTCL_DIR . 'includes/clicutcl-attribution-functions.php';
require_once CLICUTCL_DIR . 'includes/clicutcl-canonical.php';

/**
 * Initialize the plugin
 */
function clicutcl_init() {
	$plugin = new CLICUTCL_Core();
	$plugin->run();
}
add_action( 'plugins_loaded', 'clicutcl_init' );
