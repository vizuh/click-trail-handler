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
use CLICUTCL\Modules\GTM\GTM_Settings;
use CLICUTCL\Privacy\Privacy_Handler;
use CLICUTCL\Settings\Attribution_Settings;
use CLICUTCL\Server_Side\Queue;
use CLICUTCL\Tracking\Settings as Tracking_Settings;
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
						'ClickTrail failed to boot: missing class CLICUTCL\\Core\\Context. Check your autoloader mapping and release ZIP contents.',
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
		$options                  = Attribution_Settings::get_all();
		$enable_attribution       = isset( $options['enable_attribution'] ) ? (bool) $options['enable_attribution'] : true;
		$cookie_days              = isset( $options['cookie_days'] ) ? absint( $options['cookie_days'] ) : 90;
		$debug_until              = get_transient( 'clicutcl_debug_until' );
		$debug_active             = $debug_until && (int) $debug_until > time();
		$browser_events_enabled   = class_exists( 'CLICUTCL\\Tracking\\Settings' ) && Tracking_Settings::browser_event_collection_enabled();
		$events_transport_enabled = class_exists( 'CLICUTCL\\Tracking\\Settings' ) && Tracking_Settings::browser_event_transport_enabled();
		$enable_cross_domain_token = isset( $options['enable_cross_domain_token'] ) ? (bool) $options['enable_cross_domain_token'] : false;
		$events_batch_url         = $events_transport_enabled ? rest_url( 'clicutcl/v2/events/batch' ) : '';
		$events_token             = ( class_exists( 'CLICUTCL\\Tracking\\Auth' ) && ( $events_transport_enabled || $enable_cross_domain_token ) )
			? \CLICUTCL\Tracking\Auth::mint_client_token()
			: '';

		$consent_config     = $this->build_consent_bridge_config( $options, $debug_active );
		$should_load_events = ! is_admin() && ! is_feed() && ! is_robots() && ! is_trackback() && $browser_events_enabled;

		/**
		 * Filter whether the events tracking script should be loaded.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $should_load_events Whether to load the script.
		 */
		$should_load_events  = (bool) apply_filters( 'clicutcl_should_load_events_js', $should_load_events );
		$needs_consent_bridge = $consent_config['enable_consent'] || $enable_attribution || $should_load_events;

		if ( $needs_consent_bridge ) {
			wp_register_script(
				'clicutcl-consent-bridge-js',
				CLICUTCL_URL . 'assets/js/clicutcl-consent-bridge.js',
				array(),
				CLICUTCL_VERSION,
				\clicutcl_script_args( false )
			);
			wp_enqueue_script( 'clicutcl-consent-bridge-js' );
			wp_localize_script( 'clicutcl-consent-bridge-js', 'ctConsentBridgeConfig', $consent_config['bridge'] );
		}

		if ( $enable_attribution ) {
			$attribution_deps = $needs_consent_bridge ? array( 'clicutcl-consent-bridge-js' ) : array();
			wp_register_script(
				'clicutcl-attribution-js',
				CLICUTCL_URL . 'assets/js/clicutcl-attribution.js',
				$attribution_deps,
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' )
			);
			wp_enqueue_script( 'clicutcl-attribution-js' );

			wp_localize_script(
				'clicutcl-attribution-js',
				'clicutcl_config',
				$this->build_attribution_config( $options, $consent_config, $cookie_days, $debug_active, $events_batch_url, $events_token, $enable_cross_domain_token )
			);
		}

		$use_plugin_banner = $consent_config['enable_consent'] && ( 'auto' === $consent_config['cmp_source'] || 'plugin' === $consent_config['cmp_source'] );

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
					'cookieName'      => $consent_config['cookie_name'],
				)
			);
		}

		if ( $should_load_events ) {
			$events_deps = $needs_consent_bridge ? array( 'clicutcl-consent-bridge-js' ) : array();
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
					'enabled'          => (bool) $browser_events_enabled,
					'transportEnabled' => (bool) $events_transport_enabled,
					'debug'            => ! empty( $debug_active ),
					'eventsBatchUrl'   => esc_url_raw( $events_batch_url ),
					'eventsToken'      => $events_token,
					'wooCommerce'      => $this->build_woocommerce_events_config(),
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

	/**
	 * Build consent bridge configuration array.
	 *
	 * @param array $options      Attribution settings.
	 * @param bool  $debug_active Whether debug mode is active.
	 * @return array Contains 'bridge' config, 'cookie_name', 'require_consent', 'enable_consent', 'cmp_source'.
	 */
	private function build_consent_bridge_config( array $options, bool $debug_active ): array {
		$consent_settings_obj = new Modules\Consent_Mode\Consent_Mode_Settings();
		$consent_settings     = $consent_settings_obj->get();
		$enable_consent       = $consent_settings_obj->is_consent_mode_enabled();
		$consent_mode         = $consent_settings_obj->get_mode();
		$cmp_source = isset( $consent_settings['cmp_source'] ) ? sanitize_key( (string) $consent_settings['cmp_source'] ) : 'auto';
		$cmp_source = isset( Modules\Consent_Mode\Consent_Mode_Settings::ALLOWED_CMP_SOURCES[ $cmp_source ] ) ? $cmp_source : 'auto';
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

		return array(
			'bridge'          => array(
				'cookieName'      => $cookie_name,
				'cmpSource'       => $cmp_source,
				'gtmConsentKey'   => $gcm_analytics_key,
				'timeout'         => $cmp_timeout,
				'mode'            => $consent_mode,
				'fallbackGranted' => ! $require_consent,
				'debug'           => $bridge_debug,
			),
			'cookie_name'     => $cookie_name,
			'require_consent' => $require_consent,
			'enable_consent'  => $enable_consent,
			'cmp_source'      => $cmp_source,
		);
	}

	/**
	 * Build attribution script configuration array.
	 *
	 * @param array  $options                    Attribution settings.
	 * @param array  $consent_config             Consent config from build_consent_bridge_config().
	 * @param int    $cookie_days                Cookie retention days.
	 * @param bool   $debug_active               Whether debug mode is active.
	 * @param string $events_batch_url           Batch events REST URL.
	 * @param string $events_token               Client auth token.
	 * @param bool   $enable_cross_domain_token  Whether cross-domain token is enabled.
	 * @return array
	 */
	private function build_attribution_config( array $options, array $consent_config, int $cookie_days, bool $debug_active, string $events_batch_url, string $events_token, bool $enable_cross_domain_token ): array {
		return array(
			'cookieName'                => 'attribution',
			'cookieDays'                => $cookie_days,
			'consentCookieName'         => $consent_config['cookie_name'],
			'requireConsent'            => $consent_config['require_consent'],
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
			'tokenSignUrl'              => esc_url_raw( rest_url( 'clicutcl/v2/attribution-token/sign' ) ),
			'tokenVerifyUrl'            => esc_url_raw( rest_url( 'clicutcl/v2/attribution-token/verify' ) ),
			'linkAppendBlob'            => false,
		);
	}

	/**
	 * Build WooCommerce storefront event configuration for the browser runtime.
	 *
	 * @return array<string, mixed>
	 */
	private function build_woocommerce_events_config(): array {
		$config = array(
			'enabled'        => false,
			'pageType'       => 'other',
			'currency'       => '',
			'product'        => array(),
			'cart'           => array(),
			'checkout'       => array(),
			'catalogContext' => array(),
			'dataLayer'      => array(
				'enhancedContract' => false,
				'includeUserData'  => false,
			),
		);

		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'CLICUTCL\\Tracking\\Settings' ) ) {
			return $config;
		}

		$gtm_settings = class_exists( 'CLICUTCL\\Modules\\GTM\\GTM_Settings' )
			? ( new GTM_Settings() )->get()
			: array();

		$config['dataLayer'] = array(
			'enhancedContract' => ! empty( $gtm_settings['woo_enhanced_datalayer'] ),
			'includeUserData'  => ! empty( $gtm_settings['woo_include_user_data'] ),
		);

		$config['currency'] = function_exists( 'get_woocommerce_currency' )
			? sanitize_text_field( (string) get_woocommerce_currency() )
			: '';
		$config['enabled']  = Tracking_Settings::woocommerce_storefront_events_enabled();

		if ( ! $config['enabled'] ) {
			return $config;
		}

		$config['catalogContext'] = $this->build_woocommerce_catalog_context();

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = function_exists( 'get_queried_object_id' ) ? absint( get_queried_object_id() ) : 0;
			$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			$ecommerce  = $this->build_woocommerce_product_ecommerce( $product );

			if ( ! empty( $ecommerce ) ) {
				$config['pageType'] = 'product';
				$config['product']  = $ecommerce;
			}
		}

		if (
			function_exists( 'is_cart' ) &&
			is_cart() &&
			( ! function_exists( 'is_checkout' ) || ! is_checkout() )
		) {
			$ecommerce = $this->build_woocommerce_checkout_ecommerce();

			if ( ! empty( $ecommerce ) ) {
				$config['pageType'] = 'cart';
				$config['cart']     = $ecommerce;
			}
		}

		if (
			function_exists( 'is_checkout' ) &&
			is_checkout() &&
			( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) &&
			( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() )
		) {
			$ecommerce = $this->build_woocommerce_checkout_ecommerce();

			if ( ! empty( $ecommerce ) ) {
				$config['pageType'] = 'checkout';
				$config['checkout'] = $ecommerce;
			}
		}

		return $config;
	}

	/**
	 * Build catalog-context fallback naming for Woo list tracking.
	 *
	 * @return array<string,string>
	 */
	private function build_woocommerce_catalog_context(): array {
		$context = array(
			'page_type' => 'other',
			'list_name' => '',
		);

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$context['page_type'] = 'shop';
			$context['list_name'] = function_exists( 'wc_get_page_id' ) ? get_the_title( wc_get_page_id( 'shop' ) ) : __( 'Shop', 'click-trail-handler' );
			return $context;
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			if ( $term && ! empty( $term->name ) ) {
				$context['page_type'] = 'product_category';
				$context['list_name'] = sanitize_text_field( (string) $term->name );
				return $context;
			}
		}

		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			$term = get_queried_object();
			if ( $term && ! empty( $term->name ) ) {
				$context['page_type'] = 'product_tag';
				$context['list_name'] = sanitize_text_field( (string) $term->name );
				return $context;
			}
		}

		if ( is_search() ) {
			$context['page_type'] = 'search';
			$context['list_name'] = get_search_query()
				? sprintf(
					/* translators: %s: search query. */
					__( 'Search: %s', 'click-trail-handler' ),
					sanitize_text_field( (string) get_search_query() )
				)
				: __( 'Search Results', 'click-trail-handler' );
		}

		return $context;
	}

	/**
	 * Build product-page ecommerce payload for WooCommerce storefront events.
	 *
	 * @param mixed $product WooCommerce product object.
	 * @return array<string, mixed>
	 */
	private function build_woocommerce_product_ecommerce( $product ): array {
		$item = $this->build_woocommerce_product_item( $product, 1 );
		if ( empty( $item ) ) {
			return array();
		}

		return array(
			'currency'      => function_exists( 'get_woocommerce_currency' ) ? sanitize_text_field( (string) get_woocommerce_currency() ) : '',
			'value'         => isset( $item['price'] ) ? (float) $item['price'] : 0.0,
			'items'         => array( $item ),
			'item_quantity' => 1,
		);
	}

	/**
	 * Build checkout-page ecommerce payload for WooCommerce storefront events.
	 *
	 * @return array<string, mixed>
	 */
	private function build_woocommerce_checkout_ecommerce(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$items         = array();
		$item_quantity = 0;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product  = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			$quantity = isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 0;
			$item     = $this->build_woocommerce_product_item( $product, $quantity );

			if ( empty( $item ) ) {
				continue;
			}

			$items[]       = $item;
			$item_quantity += (int) $item['quantity'];
		}

		if ( empty( $items ) ) {
			return array();
		}

		$discount_codes = array();
		if ( method_exists( WC()->cart, 'get_applied_coupons' ) ) {
			$discount_codes = array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						(array) WC()->cart->get_applied_coupons()
					)
				)
			);
		}

		return array(
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? sanitize_text_field( (string) get_woocommerce_currency() ) : '',
			'order_currency' => function_exists( 'get_woocommerce_currency' ) ? sanitize_text_field( (string) get_woocommerce_currency() ) : '',
			'value'          => method_exists( WC()->cart, 'get_total' ) ? (float) WC()->cart->get_total( 'edit' ) : 0.0,
			'subtotal'       => method_exists( WC()->cart, 'get_subtotal' ) ? (float) WC()->cart->get_subtotal() : 0.0,
			'tax_total'      => method_exists( WC()->cart, 'get_total_tax' ) ? (float) WC()->cart->get_total_tax() : 0.0,
			'shipping_total' => method_exists( WC()->cart, 'get_shipping_total' ) ? (float) WC()->cart->get_shipping_total() : 0.0,
			'discount_total' => method_exists( WC()->cart, 'get_discount_total' ) ? (float) WC()->cart->get_discount_total() : 0.0,
			'discount_codes' => $discount_codes,
			'item_quantity'  => $item_quantity,
			'items'          => $items,
		);
	}

	/**
	 * Build a reusable WooCommerce item payload.
	 *
	 * @param mixed $product  WooCommerce product object.
	 * @param int   $quantity Item quantity.
	 * @return array<string, mixed>
	 */
	private function build_woocommerce_product_item( $product, int $quantity ): array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return array();
		}

		$product_id = absint( $product->get_id() );
		if ( ! $product_id ) {
			return array();
		}

		$quantity = max( 1, absint( $quantity ) );
		$variant  = '';
		if ( function_exists( 'wc_get_formatted_variation' ) && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
			$variant = wp_strip_all_tags( (string) wc_get_formatted_variation( $product, true, false, false ) );
		}

		return array(
			'item_id'    => $product_id,
			'item_name'  => sanitize_text_field( (string) $product->get_name() ),
			'price'      => method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0,
			'quantity'   => $quantity,
			'product_id' => $product_id,
			'sku'        => method_exists( $product, 'get_sku' ) ? sanitize_text_field( (string) $product->get_sku() ) : '',
			'variant'    => sanitize_text_field( $variant ),
			'categories' => $this->get_woocommerce_product_categories( $product ),
		);
	}

	/**
	 * Resolve WooCommerce product categories as plain-text names.
	 *
	 * @param mixed $product WooCommerce product object.
	 * @return array<int, string>
	 */
	private function get_woocommerce_product_categories( $product ): array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! function_exists( 'wc_get_product_terms' ) ) {
			return array();
		}

		$term_product_id = absint( $product->get_id() );
		if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$term_product_id = absint( $product->get_parent_id() );
		}

		if ( ! $term_product_id ) {
			return array();
		}

		$terms = wc_get_product_terms(
			$term_product_id,
			'product_cat',
			array(
				'fields' => 'names',
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function( $term_name ) {
						return sanitize_text_field( (string) $term_name );
					},
					$terms
				)
			)
		);
	}
}
