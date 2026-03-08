<?php

/**
 * The core plugin class.
 *
 * Handles dependency loading and module instantiation. Hook registration
 * is intentionally deferred to run() so that instantiating this class does
 * not immediately register any WordPress hooks, keeping it unit-testable.
 *
 * @package ClickTrail
 */

namespace CLICUTCL;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CLICUTCL\Admin\Admin;
use CLICUTCL\Api\Tracking_Controller;
use CLICUTCL\Integrations\WooCommerce;
use CLICUTCL\Privacy\Privacy_Handler;
use CLICUTCL\Server_Side\Queue;
use CLICUTCL\Utils\Cleanup;

/**
 * Class Plugin
 *
 * Core bootstrap class for ClickTrail. Instantiation only loads
 * dependencies and constructs module objects. Call run() to register hooks.
 */
class Plugin {

	/**
	 * Plugin context.
	 *
	 * @var Core\Context
	 */
	protected $context;

	/**
	 * Consent Mode module.
	 *
	 * @var Modules\Consent_Mode\Consent_Mode
	 */
	protected $consent_mode;

	/**
	 * GTM module.
	 *
	 * @var Modules\GTM\Web_Tag
	 */
	protected $gtm;

	/**
	 * Whether the plugin booted correctly.
	 *
	 * @var bool
	 */
	protected $booted = false;

	/**
	 * Constructor — loads dependencies and builds module objects only.
	 * Does NOT register any WordPress hooks.
	 */
	public function __construct() {
		$this->load_dependencies();

		if ( ! class_exists( 'CLICUTCL\\Core\\Context' ) ) {
			add_action(
				'admin_notices',
				function() {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p>';
					echo esc_html__(
						'ClickTrail Handler failed to boot: missing class CLICUTCL\\Core\\Context. Check your autoloader mapping and release ZIP contents.',
						'click-trail-handler'
					);
					echo '</p></div>';
				}
			);
			return;
		}

		$this->context      = new Core\Context( CLICUTCL_PLUGIN_MAIN_FILE );
		$this->consent_mode = new Modules\Consent_Mode\Consent_Mode( $this->context );
		$this->gtm          = new Modules\GTM\Web_Tag( $this->context );
		$this->booted       = true;
	}

	/**
	 * Register all hooks and start the plugin.
	 *
	 * Called explicitly after instantiation so that hook registration
	 * is decoupled from object construction.
	 *
	 * @return void
	 */
	public function run() {
		if ( ! $this->booted ) {
			return;
		}

		$this->define_admin_hooks();
		$this->define_public_hooks();

		$cleanup = new Cleanup();
		$cleanup->register();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		// Autoloader handled in bootstrap.

		// WooCommerce Admin (if WooCommerce is active).
		if ( class_exists( 'WooCommerce' ) ) {
			require_once CLICUTCL_DIR . 'includes/admin/class-clicutcl-woocommerce-admin.php';
		}
	}

	/**
	 * Register all hooks related to the admin area.
	 *
	 * @return void
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Admin( $this->context );
		$plugin_admin->init();

		if ( class_exists( 'WooCommerce' ) && class_exists( 'CLICUTCL_WooCommerce_Admin' ) ) {
			$wc_admin = new \CLICUTCL_WooCommerce_Admin();
			$wc_admin->init();
		}
	}

	/**
	 * Register all hooks related to the public-facing functionality.
	 *
	 * @return void
	 */
	private function define_public_hooks() {
		$this->consent_mode->register();
		$this->gtm->register();

		$events_logger = new Modules\Events\Events_Logger( $this->context );
		$events_logger->register();

		$privacy_handler = new Privacy_Handler();
		$privacy_handler->register_hooks();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		Queue::register();

		add_action(
			'rest_api_init',
			function() {
				$controller = new Tracking_Controller();
				$controller->register_routes();
			}
		);

		$form_integrations = new Integrations\Form_Integration_Manager();
		$form_integrations->init();

		if ( class_exists( 'WooCommerce' ) ) {
			$woocommerce_integration = new WooCommerce();
			$woocommerce_integration->init();
		}
	}

	/**
	 * Enqueue the public-facing scripts and styles.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		$options            = get_option( 'clicutcl_attribution_settings', array() );
		$enable_attribution = isset( $options['enable_attribution'] ) ? (bool) $options['enable_attribution'] : true;
		$cookie_days        = isset( $options['cookie_days'] ) ? absint( $options['cookie_days'] ) : 90;
		$debug_until        = get_transient( 'clicutcl_debug_until' );
		$debug_active       = $debug_until && (int) $debug_until > time();
		$events_transport_enabled = class_exists( 'CLICUTCL\\Tracking\\Settings' ) && \CLICUTCL\Tracking\Settings::feature_enabled( 'event_v2' );
		if ( $events_transport_enabled && class_exists( 'CLICUTCL\\Server_Side\\Dispatcher' ) ) {
			$events_transport_enabled = \CLICUTCL\Server_Side\Dispatcher::is_enabled();
		}
		$enable_cross_domain_token = isset( $options['enable_cross_domain_token'] ) ? (bool) $options['enable_cross_domain_token'] : false;
		$events_batch_url   = $events_transport_enabled ? rest_url( 'clicutcl/v2/events/batch' ) : '';
		$events_token       = ( class_exists( 'CLICUTCL\\Tracking\\Auth' ) && ( $events_transport_enabled || $enable_cross_domain_token ) )
			? \CLICUTCL\Tracking\Auth::mint_client_token()
			: '';
		$attribution_token_sign_url   = rest_url( 'clicutcl/v2/attribution-token/sign' );
		$attribution_token_verify_url = rest_url( 'clicutcl/v2/attribution-token/verify' );

		$consent_settings_obj = new Modules\Consent_Mode\Consent_Mode_Settings();
		$consent_settings     = $consent_settings_obj->get();
		$enable_consent       = $consent_settings_obj->is_consent_mode_enabled();
		$consent_mode         = $consent_settings_obj->get_mode();
		$allowed_cmp_sources  = array( 'auto', 'plugin', 'cookiebot', 'onetrust', 'complianz', 'gtm', 'custom' );
		$cmp_source           = isset( $consent_settings['cmp_source'] ) ? sanitize_key( (string) $consent_settings['cmp_source'] ) : 'auto';
		$cmp_source           = in_array( $cmp_source, $allowed_cmp_sources, true ) ? $cmp_source : 'auto';
		$cmp_timeout          = isset( $consent_settings['cmp_timeout_ms'] ) ? absint( $consent_settings['cmp_timeout_ms'] ) : 3000;
		$cmp_timeout          = min( 10000, max( 500, $cmp_timeout ) );
		$cookie_name          = isset( $consent_settings['cookie_name'] ) ? sanitize_key( (string) $consent_settings['cookie_name'] ) : 'ct_consent';
		$cookie_name          = '' !== $cookie_name ? $cookie_name : 'ct_consent';
		$gcm_analytics_key    = isset( $consent_settings['gcm_analytics_key'] ) ? sanitize_key( (string) $consent_settings['gcm_analytics_key'] ) : 'analytics_storage';
		$gcm_analytics_key    = '' !== $gcm_analytics_key ? $gcm_analytics_key : 'analytics_storage';
		$bridge_debug         = (bool) $debug_active || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		$require_consent      = isset( $options['require_consent'] ) ? (bool) $options['require_consent'] : true;
		if ( $enable_consent ) {
			$require_consent = $consent_settings_obj->is_consent_required_for_request();
		}
		$fallback_granted = ! $require_consent;

		wp_register_script(
			'clicutcl-consent-bridge-js',
			CLICUTCL_URL . 'assets/js/clicutcl-consent-bridge.js',
			array(),
			CLICUTCL_VERSION,
			\clicutcl_script_args( false )
		);
		wp_enqueue_script( 'clicutcl-consent-bridge-js' );
		wp_localize_script(
			'clicutcl-consent-bridge-js',
			'ctConsentBridgeConfig',
			array(
				'cookieName'      => $cookie_name,
				'cmpSource'       => $cmp_source,
				'gtmConsentKey'   => $gcm_analytics_key,
				'timeout'         => $cmp_timeout,
				'mode'            => $consent_mode,
				'fallbackGranted' => $fallback_granted,
				'debug'           => $bridge_debug,
			)
		);

		if ( $enable_attribution ) {
			wp_register_script(
				'clicutcl-attribution-js',
				CLICUTCL_URL . 'assets/js/clicutcl-attribution.js',
				array( 'clicutcl-consent-bridge-js' ),
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' )
			);
			wp_enqueue_script( 'clicutcl-attribution-js' );

			wp_localize_script(
				'clicutcl-attribution-js',
				'clicutcl_config',
				array(
					'cookieName'                => 'attribution',
					'cookieDays'                => $cookie_days,
					'requireConsent'            => $require_consent,
					'enableWhatsapp'            => isset( $options['enable_whatsapp'] ) ? (bool) $options['enable_whatsapp'] : true,
					'whatsappAppendAttribution' => isset( $options['whatsapp_append_attribution'] ) ? (bool) $options['whatsapp_append_attribution'] : false,
					'debug'                     => (bool) $debug_active,
					'eventsBatchUrl'            => esc_url_raw( $events_batch_url ),
					'eventsToken'               => $events_token,
					'injectEnabled'             => isset( $options['enable_js_injection'] ) ? (bool) $options['enable_js_injection'] : true,
					'injectOverwrite'           => isset( $options['inject_overwrite'] ) ? (bool) $options['inject_overwrite'] : false,
					'injectMutationObserver'    => isset( $options['inject_mutation_observer'] ) ? (bool) $options['inject_mutation_observer'] : true,
					'injectObserverTarget'      => isset( $options['inject_observer_target'] ) ? (string) $options['inject_observer_target'] : 'body',
					'injectFullBlob'            => false,
					'linkDecorateEnabled'       => isset( $options['enable_link_decoration'] ) ? (bool) $options['enable_link_decoration'] : false,
					'linkAllowedDomains'        => isset( $options['link_allowed_domains'] ) ? array_map( 'trim', explode( ',', $options['link_allowed_domains'] ) ) : array(),
					'linkSkipSigned'            => isset( $options['link_skip_signed'] ) ? (bool) $options['link_skip_signed'] : true,
					'linkAppendToken'           => $enable_cross_domain_token,
					'tokenParam'                => 'ct_token',
					'tokenMaxAgeDays'           => $cookie_days,
					'tokenSignUrl'              => esc_url_raw( $attribution_token_sign_url ),
					'tokenVerifyUrl'            => esc_url_raw( $attribution_token_verify_url ),
					'linkAppendBlob'            => false,
				)
			);
		}

		$use_plugin_banner = $enable_consent && in_array( $cmp_source, array( 'auto', 'plugin' ), true );

		if ( $use_plugin_banner ) {
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
				array( 'clicutcl-consent-bridge-js' ),
				CLICUTCL_VERSION,
				\clicutcl_script_args( false )
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
					'cookieName'      => $cookie_name,
				)
			);
		}

		$should_load_events = ! is_admin() && ! is_feed() && ! is_robots() && ! is_trackback() && $enable_attribution;

		/**
		 * Filter whether the events tracking script should be loaded.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $should_load_events Whether to load the script.
		 */
		if ( apply_filters( 'clicutcl_should_load_events_js', $should_load_events ) ) {
			$events_deps = array( 'clicutcl-consent-bridge-js' );
			if ( $enable_attribution ) {
				$events_deps[] = 'clicutcl-attribution-js';
			}

			wp_register_script(
				'clicutcl-events-js',
				CLICUTCL_URL . 'assets/js/clicutcl-events.js',
				$events_deps,
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' )
			);
			wp_enqueue_script( 'clicutcl-events-js' );
			wp_localize_script(
				'clicutcl-events-js',
				'clicutclEventsConfig',
				array(
					'debug'            => ! empty( $debug_active ),
					'eventsBatchUrl'   => esc_url_raw( $events_batch_url ),
					'eventsToken'      => $events_token,
					'thankYouMatchers' => array_values(
						(array) apply_filters( 'clicutcl_thank_you_matchers', array() )
					),
					'iframeOrigins'    => array_values(
						(array) apply_filters(
							'clicutcl_iframe_origin_allowlist',
							array(
								'calendly.com',
								'typeform.com',
								'hubspot.com',
							)
						)
					),
				)
			);
		}
	}
}
