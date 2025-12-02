<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 */
class ClickTrail_Core {

	/**
	 * Plugin context.
	 *
	 * @var ClickTrail\Core\Context
	 */
	protected $context;

	/**
	 * Consent Mode module.
	 *
	 * @var ClickTrail\Modules\Consent_Mode\Consent_Mode
	 */
	protected $consent_mode;

	/**
	 * GTM module.
	 *
	 * @var ClickTrail\Modules\GTM\Web_Tag
	 */
	protected $gtm;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->context = new ClickTrail\Core\Context( CLICKTRAIL_PLUGIN_MAIN_FILE );
		
		// Initialize Modules
		$this->consent_mode = new ClickTrail\Modules\Consent_Mode\Consent_Mode( $this->context );
		$this->gtm          = new ClickTrail\Modules\GTM\Web_Tag( $this->context );

		$this->register_cpt();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		// Core
		require_once CLICKTRAIL_DIR . 'includes/Core/Context.php';
		require_once CLICKTRAIL_DIR . 'includes/Core/Storage/Setting.php';

		// Modules
		require_once CLICKTRAIL_DIR . 'includes/Modules/Consent_Mode/Regions.php';
		require_once CLICKTRAIL_DIR . 'includes/Modules/Consent_Mode/Consent_Mode_Settings.php';
		require_once CLICKTRAIL_DIR . 'includes/Modules/Consent_Mode/Consent_Mode.php';
		
		require_once CLICKTRAIL_DIR . 'includes/Modules/GTM/GTM_Settings.php';
		require_once CLICKTRAIL_DIR . 'includes/Modules/GTM/Web_Tag.php';

		require_once CLICKTRAIL_DIR . 'includes/Modules/Events/Events_Logger.php';

		// Admin
		require_once CLICKTRAIL_DIR . 'includes/admin/class-ct-settings.php';

		// Integrations
		require_once CLICKTRAIL_DIR . 'includes/integrations/class-clicktrail-form-integrations.php';
		require_once CLICKTRAIL_DIR . 'includes/integrations/class-clicktrail-woocommerce.php';

		// WooCommerce Admin (if WooCommerce is active)
		if ( class_exists( 'WooCommerce' ) ) {
			require_once CLICKTRAIL_DIR . 'includes/admin/class-clicktrail-woocommerce-admin.php';
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
		register_post_type( 'ct_wa_click', array(
			'labels' => array(
				'name' => 'WhatsApp Clicks',
				'singular_name' => 'WhatsApp Click'
			),
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => 'clicktrail',
			'capability_type' => 'post',
			'capabilities' => array(
				'create_posts' => 'do_not_allow'
			),
			'map_meta_cap' => true,
			'supports' => array( 'title' )
		) );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 */
	private function define_admin_hooks() {
		$plugin_settings = new ClickTrail_Admin( $this->context );
		$plugin_settings->init();

		// AJAX for PII Logging
		add_action( 'wp_ajax_ct_log_pii_risk', array( $plugin_settings, 'ajax_log_pii_risk' ) );
		add_action( 'wp_ajax_nopriv_ct_log_pii_risk', array( $plugin_settings, 'ajax_log_pii_risk' ) );

		// AJAX for WhatsApp Click Logging
		add_action( 'wp_ajax_ct_log_wa_click', array( $this, 'ajax_log_wa_click' ) );
		add_action( 'wp_ajax_nopriv_ct_log_wa_click', array( $this, 'ajax_log_wa_click' ) );

		// Initialize WooCommerce Admin features
		if ( class_exists( 'WooCommerce' ) && class_exists( 'ClickTrail_WooCommerce_Admin' ) ) {
			$wc_admin = new ClickTrail_WooCommerce_Admin();
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
		$events_logger = new ClickTrail\Modules\Events\Events_Logger( $this->context );
		$events_logger->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		
		// Initialize Integrations
		$form_integrations = new ClickTrail_Form_Integrations();
		$form_integrations->init();

		$woocommerce_integration = new ClickTrail_WooCommerce_Integration();
		$woocommerce_integration->init();
	}

	/**
	 * Enqueue the public-facing scripts and styles.
	 */
	public function enqueue_scripts() {
		$options = get_option( 'clicktrail_attribution_settings', array() );
		$enable_attribution = isset( $options['enable_attribution'] ) ? (bool) $options['enable_attribution'] : true;
		$cookie_days = isset( $options['cookie_days'] ) ? absint( $options['cookie_days'] ) : 90;
		
		// Use new Consent Mode settings
		$consent_settings = new ClickTrail\Modules\Consent_Mode\Consent_Mode_Settings();
		$enable_consent = $consent_settings->is_consent_mode_enabled();
		
		// Legacy setting for "Require Consent"
		$require_consent = isset( $options['require_consent'] ) ? (bool) $options['require_consent'] : 1;

		// Attribution Script
		if ( $enable_attribution ) {
			wp_enqueue_script(
				'clicktrail-attribution-js',
				CLICKTRAIL_URL . 'assets/js/clicktrail-attribution.js',
				array(),
				CLICKTRAIL_VERSION,
				false // Load in Head
			);

			wp_localize_script( 'clicktrail-attribution-js', 'clickTrailConfig', array(
				'cookieName' => 'attribution',
				'cookieDays' => $cookie_days,
				'requireConsent' => $require_consent,
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( CLICKTRAIL_PII_NONCE_ACTION ),
				'enableWhatsapp' => isset( $options['enable_whatsapp'] ) ? (bool) $options['enable_whatsapp'] : true,
				'whatsappAppendAttribution' => isset( $options['whatsapp_append_attribution'] ) ? (bool) $options['whatsapp_append_attribution'] : false,
				'whatsappLogClicks' => isset( $options['whatsapp_log_clicks'] ) ? (bool) $options['whatsapp_log_clicks'] : false
			));
		}

		// Consent Script & Style (Only if enabled in new settings)
		if ( $enable_consent ) {
			wp_enqueue_style(
				'clicktrail-consent-css',
				CLICKTRAIL_URL . 'assets/css/clicktrail-consent.css',
				array(),
				CLICKTRAIL_VERSION,
				'all'
			);

			wp_enqueue_script(
				'clicktrail-consent-js',
				CLICKTRAIL_URL . 'assets/js/clicktrail-consent.js',
				array(),
				CLICKTRAIL_VERSION,
				true // Footer
			);
		}

		// Events Tracking Script
		wp_enqueue_script(
			'clicktrail-events-js',
			CLICKTRAIL_URL . 'assets/js/clicktrail-events.js',
			array(),
			CLICKTRAIL_VERSION,
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
		// No nonce check needed for public tracking
		$wa_href = isset( $_POST['wa_href'] ) ? esc_url_raw( $_POST['wa_href'] ) : '';
		$wa_location = isset( $_POST['wa_location'] ) ? esc_url_raw( $_POST['wa_location'] ) : '';
		$attribution = isset( $_POST['attribution'] ) ? json_decode( stripslashes( $_POST['attribution'] ), true ) : array();
		
		if ( function_exists( 'clicktrail_sanitize_attribution_data' ) ) {
			$attribution = clicktrail_sanitize_attribution_data( $attribution );
		}

		if ( ! $wa_href ) {
			wp_send_json_error( array( 'message' => 'Missing wa_href' ) );
		}

		// Create post
		$post_id = wp_insert_post( array(
			'post_type' => 'ct_wa_click',
			'post_title' => 'WhatsApp Click - ' . date( 'Y-m-d H:i:s' ),
			'post_status' => 'publish'
		) );

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
