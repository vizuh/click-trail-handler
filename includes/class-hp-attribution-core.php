<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 */
class HP_Attribution_Core {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      HP_Attribution_Loader    $loader    Maintains and registers all hooks for the plugin.
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
		require_once HP_ATTRIBUTION_DIR . 'includes/admin/class-hp-settings.php';
		
		// Integrations
		require_once HP_ATTRIBUTION_DIR . 'includes/integrations/class-hp-form-integrations.php';
		require_once HP_ATTRIBUTION_DIR . 'includes/integrations/class-hp-woocommerce.php';
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_settings = new HP_Attribution_Admin();
		$plugin_settings->init();
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
		$form_integrations = new HP_Form_Integrations();
		$form_integrations->init();

		$woocommerce_integration = new HP_WooCommerce_Integration();
		$woocommerce_integration->init();
	}

	/**
	 * Enqueue the public-facing scripts and styles.
	 */
	public function enqueue_scripts() {
		$options = get_option( 'hp_attribution_settings' );
		$cookie_days = isset( $options['cookie_days'] ) ? $options['cookie_days'] : 90;
		$enable_consent = isset( $options['enable_consent_banner'] ) ? $options['enable_consent_banner'] : 0;

		// Attribution Script
		wp_enqueue_script(
			'hp-attribution-js',
			HP_ATTRIBUTION_URL . 'assets/js/hp-attribution.js',
			array(), 
			HP_ATTRIBUTION_VERSION,
			false 
		);

		wp_localize_script( 'hp-attribution-js', 'hpAttributionConfig', array(
			'cookieName' => 'hp_attribution',
			'cookieDays' => $cookie_days,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
		));

		// Consent Script & Style
		if ( $enable_consent ) {
			wp_enqueue_style(
				'hp-consent-css',
				HP_ATTRIBUTION_URL . 'assets/css/hp-consent.css',
				array(),
				HP_ATTRIBUTION_VERSION,
				'all'
			);

			wp_enqueue_script(
				'hp-consent-js',
				HP_ATTRIBUTION_URL . 'assets/js/hp-consent.js',
				array(),
				HP_ATTRIBUTION_VERSION,
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
