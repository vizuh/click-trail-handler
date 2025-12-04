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
define( 'CLICKTRAIL_VERSION', '1.1.0' );
define( 'CLICKTRAIL_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLICKTRAIL_URL', plugin_dir_url( __FILE__ ) );
define( 'CLICKTRAIL_BASENAME', plugin_basename( __FILE__ ) );
define( 'CLICKTRAIL_PLUGIN_MAIN_FILE', __FILE__ );
define( 'CLICKTRAIL_PII_NONCE_ACTION', 'clicktrail_pii_nonce' );



// Include Core Class
require_once CLICKTRAIL_DIR . 'includes/class-clicktrail-core.php';
require_once CLICKTRAIL_DIR . 'includes/clicktrail-attribution-functions.php';
require_once CLICKTRAIL_DIR . 'includes/clicktrail-canonical.php';

/**
 * Initialize the plugin
 */
function clicktrail_init() {
	$plugin = new ClickTrail_Core();
	$plugin->run();
}
add_action( 'plugins_loaded', 'clicktrail_init' );
