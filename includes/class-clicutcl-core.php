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
use CLICUTCL\Post_Types\WhatsApp_Click;
use CLICUTCL\Ajax\Log_Handler;

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
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->context = new CLICUTCL\Core\Context( CLICUTCL_PLUGIN_MAIN_FILE );
		
		// Initialize Modules
		$this->consent_mode = new CLICUTCL\Modules\Consent_Mode\Consent_Mode( $this->context );
		$this->gtm          = new CLICUTCL\Modules\GTM\Web_Tag( $this->context );

		$this->register_cpt();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		// Autoloader
		require_once CLICUTCL_DIR . 'includes/class-autoloader.php';
		\CLICUTCL\Autoloader::run();

		// Non-namespaced files
		// require_once CLICUTCL_DIR . 'includes/integrations/class-clicutcl-form-integrations.php'; // CLICUTCL_Form_Integrations (Not namespaced)
		require_once CLICUTCL_DIR . 'includes/integrations/class-clicutcl-form-integrations.php'; // CLICUTCL_Form_Integrations (Not namespaced)
		require_once CLICUTCL_DIR . 'includes/integrations/class-clicutcl-woocommerce.php'; // CLICUTCL_WooCommerce_Integration (Not namespaced)

		// WooCommerce Admin (if WooCommerce is active)
		if ( class_exists( 'WooCommerce' ) ) {
			require_once CLICUTCL_DIR . 'includes/admin/class-clicutcl-woocommerce-admin.php'; // CLICUTCL_WooCommerce_Admin (Not namespaced)
		}
	}

	/**
	 * Register Custom Post Types
	 */
	private function register_cpt() {
		$wa_click = new WhatsApp_Click();
		add_action( 'init', array( $wa_click, 'register' ) );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Admin( $this->context );
		$plugin_admin->init();

		// Initialize AJAX Handler
		$log_handler = new Log_Handler();
		$log_handler->register();

		// AJAX hooks (Admin specific)
		add_action( 'wp_ajax_clicutcl_log_pii_risk', array( $plugin_admin, 'ajax_log_pii_risk' ) );

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
		$form_integrations = new CLICUTCL_Form_Integrations();
		$form_integrations->init();

		$woocommerce_integration = new CLICUTCL_WooCommerce_Integration();
		$woocommerce_integration->init();
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
					'ajaxUrl'                   => admin_url( 'admin-ajax.php' ),
					'nonce'                     => wp_create_nonce( CLICUTCL_PII_NONCE_ACTION ),
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
		// In a more complex setup we might use a Loader class, 
		// but for now we just rely on the constructor adding hooks.
	}

	/**
	 * AJAX handler for logging WhatsApp clicks
	 */

}
