<?php
/**
 * Plugin Name: ClickTrail – UTM, Click ID & Ad Tracking (with Consent)
 * Plugin URI:  https://github.com/vizuh/click-trail-handler
 * Description: Complete consent management and marketing attribution solution. Captures UTM parameters and click IDs, manages user consent with Google Consent Mode, and tracks attribution across forms, WooCommerce, and WhatsApp.
 * Version:     1.2.3
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
define( 'CLICUTCL_VERSION', '1.2.3' );
define( 'CLICUTCL_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLICUTCL_URL', plugin_dir_url( __FILE__ ) );
define( 'CLICUTCL_BASENAME', plugin_basename( __FILE__ ) );
define( 'CLICUTCL_PLUGIN_MAIN_FILE', __FILE__ );
define( 'CLICUTCL_PII_NONCE_ACTION', 'clicutcl_pii_nonce' );

/**
 * Bootstrap autoloading safely (Composer if present, then plugin autoloader).
 */
function clicutcl_bootstrap(): void {
	// Composer autoloader (if you ship /vendor in releases)
	$composer = CLICUTCL_DIR . 'vendor/autoload.php';
	if ( file_exists( $composer ) ) {
		require_once $composer;
	}

	// Plugin autoloader
	$autoloader = CLICUTCL_DIR . 'includes/class-autoloader.php';
	if ( file_exists( $autoloader ) ) {
		require_once $autoloader;
		if ( class_exists( 'CLICUTCL\\Autoloader' ) ) {
			CLICUTCL\Autoloader::run();
		}
	}

	// Hard fallback: ensure Context is loadable even if autoloader mapping is wrong.
	if ( ! class_exists( 'CLICUTCL\\Core\\Context' ) ) {
		$candidates = array(
			CLICUTCL_DIR . 'includes/core/class-context.php',
			CLICUTCL_DIR . 'includes/Core/class-context.php',
			CLICUTCL_DIR . 'includes/core/Context.php',
			CLICUTCL_DIR . 'includes/Core/Context.php',
			CLICUTCL_DIR . 'includes/class-context.php',
		);
		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
				if ( class_exists( 'CLICUTCL\\Core\\Context' ) ) {
					break;
				}
			}
		}
	}
}

clicutcl_bootstrap();

// Include Core Class
require_once CLICUTCL_DIR . 'includes/class-clicutcl-core.php';
// require_once CLICUTCL_DIR . 'includes/clicutcl-attribution-functions.php'; // Moved to CLICUTCL\Utils\Attribution
require_once CLICUTCL_DIR . 'includes/clicutcl-canonical.php';

// Translations are loaded automatically by WordPress 4.6+ for plugins hosted on WordPress.org.
// See: https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#loading-text-domain

// Activation Hook
register_activation_hook( __FILE__, function() {
	// Autoloader is already loaded globally
	require_once CLICUTCL_DIR . 'includes/database/class-installer.php';
	CLICUTCL\Database\Installer::run();

	if ( ! wp_next_scheduled( 'clicutcl_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'clicutcl_daily_cleanup' );
	}
} );

register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook( 'clicutcl_daily_cleanup' );
} );

/**
 * Initialize the plugin
 */
function clicutcl_init() {
	// Ensure bootstrap has run (in case another file called init directly)
	if ( ! class_exists( 'CLICUTCL\\Core\\Context' ) ) {
		clicutcl_bootstrap();
	}

	// Preflight: never fatal the site if a required class is missing.
	if ( ! class_exists( 'CLICUTCL\\Core\\Context' ) 
		|| ! class_exists( 'CLICUTCL\\Modules\\Consent_Mode\\Consent_Mode' ) 
		|| ! class_exists( 'CLICUTCL\\Modules\\GTM\\Web_Tag' )
		|| ! class_exists( 'CLICUTCL_Core' ) 
	) {
		add_action( 'admin_notices', function() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'ClickTrail Handler could not start because a required class is missing. This usually means the release ZIP is missing files or the autoloader mapping is incorrect.',
				'click-trail-handler'
			);
			echo '</p></div>';
		} );
		return;
	}

	$plugin = new CLICUTCL_Core();
	$plugin->run();
}
add_action( 'init', 'clicutcl_init', 20 );
