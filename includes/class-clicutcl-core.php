<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

		// Legacy / Non-namespaced files
		require_once CLICUTCL_DIR . 'includes/admin/class-clicutcl-admin.php'; // CLICUTCL_Admin (Not namespaced)
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
		add_action( 'init', array( $this, 'register_whatsapp_cpt' ) );
	}

	/**
	 * Register WhatsApp Click CPT
	 */
	public function register_whatsapp_cpt() {
		register_post_type(
			'clicutcl_wa_click',
			array(
				'labels'       => array(
					'name'          => __( 'WhatsApp Clicks', 'click-trail-handler' ),
					'singular_name' => __( 'WhatsApp Click', 'click-trail-handler' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'clicutcl-settings',
				'capability_type' => 'post',
				'capabilities' => array(
					'create_posts' => 'do_not_allow',
				),
				'map_meta_cap' => true,
				'supports'     => array( 'title' ),
			)
		);
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 */
	private function define_admin_hooks() {
		$plugin_admin = new CLICUTCL_Admin( $this->context );
		$plugin_admin->init();

		// AJAX hooks
		add_action( 'wp_ajax_clicutcl_log_pii_risk', array( $plugin_admin, 'ajax_log_pii_risk' ) );
		add_action( 'wp_ajax_clicutcl_log_wa_click', array( $this, 'ajax_log_wa_click' ) );
		add_action( 'wp_ajax_nopriv_clicutcl_log_wa_click', array( $this, 'ajax_log_wa_click' ) );

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
		
		// Legacy setting for "Require Consent"
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
				'clickTrailConfig',
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
	public function ajax_log_wa_click() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), CLICUTCL_PII_NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
		}

		$wa_href     = isset( $_POST['wa_href'] ) ? esc_url_raw( wp_unslash( $_POST['wa_href'] ) ) : '';
		$wa_location = isset( $_POST['wa_location'] ) ? esc_url_raw( wp_unslash( $_POST['wa_location'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string is decoded and then sanitized.
		$raw_attribution_json = isset( $_POST['attribution'] ) ? wp_unslash( $_POST['attribution'] ) : '';
		$raw_attribution      = json_decode( $raw_attribution_json, true );
		$attribution          = is_array( $raw_attribution ) ? clicutcl_sanitize_attribution_data( $raw_attribution ) : array();

		if ( ! $wa_href ) {
			wp_send_json_error( array( 'message' => 'Missing wa_href' ) );
		}

		// Create post
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'clicutcl_wa_click',
				'post_title'  => 'WhatsApp Click - ' . gmdate( 'Y-m-d H:i:s' ),
				'post_status' => 'publish',
			)
		);

		if ( ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, '_wa_href', $wa_href );
			update_post_meta( $post_id, '_wa_location', $wa_location );
			update_post_meta( $post_id, '_attribution', $attribution );
			wp_send_json_success( array( 'post_id' => $post_id ) );
		} else {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}
	}
}
