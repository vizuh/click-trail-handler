<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 */
class ClickTrail_Core {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
 * @var      CT_Attribution_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		// Admin
                require_once CLICKTRAIL_DIR . 'includes/admin/class-ct-settings.php';

                // Integrations
                require_once CLICKTRAIL_DIR . 'includes/integrations/class-ct-form-integrations.php';
                require_once CLICKTRAIL_DIR . 'includes/integrations/class-ct-woocommerce.php';
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_settings = new ClickTrail_Admin();
		$plugin_settings->init();

		// AJAX for PII Logging
                add_action( 'wp_ajax_ct_log_pii_risk', array( $plugin_settings, 'ajax_log_pii_risk' ) );
                add_action( 'wp_ajax_nopriv_ct_log_pii_risk', array( $plugin_settings, 'ajax_log_pii_risk' ) );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {
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
                $options = get_option( 'ct_attribution_settings', array() );
                $enable_attribution = isset( $options['enable_attribution'] ) ? (bool) $options['enable_attribution'] : true;
                $cookie_days = isset( $options['cookie_days'] ) ? absint( $options['cookie_days'] ) : 90;
                $enable_consent = isset( $options['enable_consent_banner'] ) ? (bool) $options['enable_consent_banner'] : 1;
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
                                'cookieName' => 'ct_attribution',
                                'cookieDays' => $cookie_days,
                                'requireConsent' => $require_consent,
                                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                                'nonce'      => wp_create_nonce( 'clicktrail_pii_nonce' ),
                        ));
                }

		// Consent Script & Style
		if ( $enable_consent ) {
			wp_enqueue_style(
                                'ct-consent-css',
                                CLICKTRAIL_URL . 'assets/css/ct-consent.css',
				array(),
				CLICKTRAIL_VERSION,
				'all'
			);

			wp_enqueue_script(
                                'ct-consent-js',
                                CLICKTRAIL_URL . 'assets/js/ct-consent.js',
				array(),
				CLICKTRAIL_VERSION,
				true // Footer
			);
		}
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		// In a more complex setup we might use a Loader class, 
		// but for now we just rely on the constructor adding hooks.
	}
}
