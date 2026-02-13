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
use CLICUTCL\Server_Side\Queue;

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

		Queue::register();
		
		// Initialize Integrations
		$form_integrations = new CLICUTCL\Integrations\Form_Integration_Manager();
		$form_integrations->init();


		if ( class_exists( 'WooCommerce' ) ) {
			$woocommerce_integration = new WooCommerce();
			$woocommerce_integration->init();
		}

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
		// Strategies:
		// 1. Attribution JS loads in HEAD to ensure immediate capture of UTMs/GCLIDs before any redirects or other scripts run.
		// 2. It also handles link decoration reliably before user interaction.
		// 3. Heavier scripts (Consent, Events) are correctly deferred to the footer for performance.
		if ( $enable_attribution ) {
			wp_register_script(
				'clicutcl-attribution-js',
				CLICUTCL_URL . 'assets/js/clicutcl-attribution.js',
				array(),
				CLICUTCL_VERSION,
				\clicutcl_script_args( false )
			);
			wp_enqueue_script( 'clicutcl-attribution-js' ); // Load in Head: Essential for immediate UTM/GCLID capture before redirects and link decoration.

			wp_localize_script(
				'clicutcl-attribution-js',
				'clicutcl_config',
				array(
					'cookieName'                => 'attribution',
					'cookieDays'                => $cookie_days,
					'requireConsent'            => $require_consent,
					'enableWhatsapp'            => isset( $options['enable_whatsapp'] ) ? (bool) $options['enable_whatsapp'] : true,
					'whatsappAppendAttribution' => isset( $options['whatsapp_append_attribution'] ) ? (bool) $options['whatsapp_append_attribution'] : false,
					'whatsappLogClicks'         => isset( $options['whatsapp_log_clicks'] ) ? (bool) $options['whatsapp_log_clicks'] : false,
					'restUrl'                   => get_rest_url( null, 'clicutcl/v1/log' ),
					'publicLogUrl'              => get_rest_url( null, 'clicutcl/v1/wa-click' ),
					'nonce'                     => wp_create_nonce( 'wp_rest' ), // REST Nonce
					
					// JS Injection Config
					'injectEnabled'             => isset( $options['enable_js_injection'] ) ? (bool) $options['enable_js_injection'] : true,
					'injectOverwrite'           => isset( $options['inject_overwrite'] ) ? (bool) $options['inject_overwrite'] : false,
					'injectMutationObserver'    => isset( $options['inject_mutation_observer'] ) ? (bool) $options['inject_mutation_observer'] : true,
					'injectObserverTarget'      => isset( $options['inject_observer_target'] ) ? (string) $options['inject_observer_target'] : 'body', // Default: body, user can override via filter/option
					'injectFullBlob'            => false, // Reserved for future use

					// Link Decoration Config
					'linkDecorateEnabled'       => isset( $options['enable_link_decoration'] ) ? (bool) $options['enable_link_decoration'] : false,
					'linkAllowedDomains'        => isset( $options['link_allowed_domains'] ) ? array_map('trim', explode(',', $options['link_allowed_domains'])) : [],
					'linkSkipSigned'            => isset( $options['link_skip_signed'] ) ? (bool) $options['link_skip_signed'] : true,
					'linkAppendToken'           => isset( $options['enable_cross_domain_token'] ) ? (bool) $options['enable_cross_domain_token'] : false,
					'tokenParam'                => 'ct_token',
					'tokenMaxAgeDays'           => $cookie_days,
					'linkAppendBlob'            => false, // Reserved for future use
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

			wp_register_script(
				'clicutcl-consent-js',
				CLICUTCL_URL . 'assets/js/clicutcl-consent.js',
				array(),
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' ) // Footer
			);
			wp_enqueue_script( 'clicutcl-consent-js' );

			wp_localize_script(
				'clicutcl-consent-js',
				'clicutclConsentL10n',
				array(
					'bannerText'      => __( 'We use cookies to improve your experience and analyze traffic.', 'click-trail-handler' ),
					'readMore'        => __( 'Read more', 'click-trail-handler' ),
					'acceptAll'       => __( 'Accept All', 'click-trail-handler' ),
					'rejectEssential' => __( 'Reject Non-Essential', 'click-trail-handler' ),
					'privacyUrl'      => get_privacy_policy_url() ?: '#',
				)
			);
		}

		// Events Tracking Script
		// Conditional loading: Front-end only, not feeds/robots, and respects attribution setting/filter.
		$should_load_events = ! is_admin() && ! is_feed() && ! is_robots() && ! is_trackback();
		
		if ( $enable_attribution ) {
			// If attribution is on, we generally want events.
			// But allow filter to override.
		} else {
			// If attribution is off, maybe we don't want events? 
			// The request said "Always-on... could be seen as avoidable...".
			// Let's assume if attribution is disabled, this might be too. 
			// However, localizing it to the same option seems safest for "state".
			$should_load_events = false; 
		}

		/**
		 * Filter to determine if the events tracking script should be loaded.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $should_load_events Whether to load the script.
		 */
		if ( apply_filters( 'clicutcl_should_load_events_js', $should_load_events ) ) {
			wp_register_script(
				'clicutcl-events-js',
				CLICUTCL_URL . 'assets/js/clicutcl-events.js',
				array(),
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' ) // Footer
			);
			wp_enqueue_script( 'clicutcl-events-js' );
		}
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
