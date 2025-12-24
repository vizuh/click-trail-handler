<?php

declare(strict_types=1);

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CLICUTCL\Admin\Admin;
use CLICUTCL\Integrations\WooCommerce;

use CLICUTCL\Api\Log_Controller;
use CLICUTCL\Utils\Cleanup;

class CLICUTCL_Core {

	/**
	 * Plugin context.
	 *
	 * @var CLICUTCL\Core\Context
	 */
	protected $context;

	/**
	 * Consent Mode module.
	 *
	 * @var CLICUTCL\Modules\Consent_Mode\Consent_Mode
	 */
	protected $consent_mode;

	/**
	 * GTM module.
	 *
	 * @var CLICUTCL\Modules\GTM\Web_Tag
	 */
	protected $gtm;

	/**
	 * Whether the plugin booted correctly.
	 *
	 * @var bool
	 */
	protected $booted = false;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		$this->load_dependencies();

		if ( ! class_exists( 'CLICUTCL\\Core\\Context' ) ) {
			add_action( 'admin_notices', function() {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p>';
				echo esc_html__(
					'ClickTrail Handler failed to boot: missing class CLICUTCL\\Core\\Context. Check your autoloader mapping and release ZIP contents.',
					'click-trail-handler'
				);
				echo '</p></div>';
			} );
			return;
		}

		$this->context = new CLICUTCL\Core\Context( CLICUTCL_PLUGIN_MAIN_FILE );
		
		// Initialize Modules
		$this->consent_mode = new CLICUTCL\Modules\Consent_Mode\Consent_Mode( $this->context );
		$this->gtm          = new CLICUTCL\Modules\GTM\Web_Tag( $this->context );


		$this->define_admin_hooks();
		$this->define_public_hooks();
		
		$cleanup = new Cleanup();
		$cleanup->register();
		$this->booted = true;
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		// Autoloader handled in bootstrap

		// WooCommerce Admin (if WooCommerce is active)
		if ( class_exists( 'WooCommerce' ) ) {
			require_once CLICUTCL_DIR . 'includes/admin/class-clicutcl-woocommerce-admin.php'; // CLICUTCL_WooCommerce_Admin (Not namespaced)
		}
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Admin( $this->context );
		$plugin_admin->init();

		// Initialize WooCommerce Admin features
		if ( class_exists( 'WooCommerce' ) && class_exists( 'CLICUTCL_WooCommerce_Admin' ) ) {
			$wc_admin = new CLICUTCL_WooCommerce_Admin();
			$wc_admin->init();
		}
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 */
	private function define_public_hooks() {
		// Register Modules
		$this->consent_mode->register();
		$this->gtm->register();

		// Register Events Logger
		$events_logger = new CLICUTCL\Modules\Events\Events_Logger( $this->context );
		$events_logger->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		
		// Initialize Integrations
		$form_integrations = new CLICUTCL\Integrations\Form_Integration_Manager();
		$form_integrations->init();


		$woocommerce_integration = new WooCommerce();
		$woocommerce_integration->init();

		// Register REST API
		add_action( 'rest_api_init', function() {
			$controller = new Log_Controller();
			$controller->register_routes();
		} );
	}

	/**
	 * Enqueue the public-facing scripts and styles.
	 */
	public function enqueue_scripts() {
		$options            = get_option( 'clicutcl_attribution_settings', array() );
		$enable_attribution = isset( $options['enable_attribution'] ) ? (bool) $options['enable_attribution'] : true;
		$cookie_days        = isset( $options['cookie_days'] ) ? absint( $options['cookie_days'] ) : 90;
		
		// Use new Consent Mode settings
		$consent_settings = new CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings();
		$enable_consent   = $consent_settings->is_consent_mode_enabled();
		
		$require_consent = isset( $options['require_consent'] ) ? (bool) $options['require_consent'] : 1;

		// Attribution Script
		if ( $enable_attribution ) {
			wp_enqueue_script(
				'clicutcl-attribution-js',
				CLICUTCL_URL . 'assets/js/clicutcl-attribution.js',
				array(),
				CLICUTCL_VERSION,
				false // Load in Head
			);

			wp_localize_script(
				'clicutcl-attribution-js',
				'clicutcl_config',
				array(
					'cookieName'                => 'attribution',
					'cookieDays'                => $cookie_days,
					'requireConsent'            => $require_consent,
					'restUrl'                   => get_rest_url( null, 'clicutcl/v1/log' ),
					'nonce'                     => wp_create_nonce( 'wp_rest' ), // REST Nonce
					'enableWhatsapp'            => isset( $options['enable_whatsapp'] ) ? (bool) $options['enable_whatsapp'] : true,
					'whatsappAppendAttribution' => isset( $options['whatsapp_append_attribution'] ) ? (bool) $options['whatsapp_append_attribution'] : false,
					'whatsappLogClicks'         => isset( $options['whatsapp_log_clicks'] ) ? (bool) $options['whatsapp_log_clicks'] : false,
				)
			);
		}

		// Consent Script & Style (Only if enabled in new settings)
		if ( $enable_consent ) {
			wp_enqueue_style(
				'clicutcl-consent-css',
				CLICUTCL_URL . 'assets/css/clicutcl-consent.css',
				array(),
				CLICUTCL_VERSION,
				'all'
			);

			wp_enqueue_script(
				'clicutcl-consent-js',
				CLICUTCL_URL . 'assets/js/clicutcl-consent.js',
				array(),
				CLICUTCL_VERSION,
				true // Footer
			);
		}

		// Events Tracking Script
		wp_enqueue_script(
			'clicutcl-events-js',
			CLICUTCL_URL . 'assets/js/clicutcl-events.js',
			array(),
			CLICUTCL_VERSION,
			true // Footer
		);
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run() {
		if ( ! $this->booted ) {
			return;
		}
		// In a more complex setup we might use a Loader class, 
		// but for now we just rely on the constructor adding hooks.
	}

	/**
	 * AJAX handler for logging WhatsApp clicks
	 */

}
