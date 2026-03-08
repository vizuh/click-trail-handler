<?php
/**
 * ClickTrail Admin Settings
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

use CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings;
use CLICUTCL\Modules\GTM\GTM_Settings;
use CLICUTCL\Server_Side\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once CLICUTCL_DIR . 'includes/admin/traits/trait-admin-consent-mode.php';
require_once CLICUTCL_DIR . 'includes/admin/traits/trait-admin-diagnostics-ajax.php';
require_once CLICUTCL_DIR . 'includes/admin/traits/trait-admin-pages.php';

/**
 * Class Admin
 */
class Admin {
	use Admin_Consent_Mode_Trait;
	use Admin_Diagnostics_Ajax_Trait;
	use Admin_Pages_Trait;

	/**
	 * Context.
	 *
	 * @var \CLICUTCL\Core\Context
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param \CLICUTCL\Core\Context $context Context.
	 */
	public function __construct( $context ) {
		$this->context = $context;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Run early so the parent menu exists before CPT submenus attach.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 1 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'display_pii_warning' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		
		// AJAX hooks for Admin/Settings functionality
		add_action( 'wp_ajax_clicutcl_log_pii_risk', array( $this, 'ajax_log_pii_risk' ) );
		add_action( 'wp_ajax_clicutcl_test_endpoint', array( $this, 'ajax_test_endpoint' ) );
		add_action( 'wp_ajax_clicutcl_toggle_debug', array( $this, 'ajax_toggle_debug' ) );
		add_action( 'wp_ajax_clicutcl_purge_tracking_data', array( $this, 'ajax_purge_tracking_data' ) );
		add_action( 'wp_ajax_clicutcl_get_tracking_v2_settings', array( $this, 'ajax_get_tracking_v2_settings' ) );
		add_action( 'wp_ajax_clicutcl_save_tracking_v2_settings', array( $this, 'ajax_save_tracking_v2_settings' ) );

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'network_admin_menu' ) );
			add_action( 'network_admin_edit_clicutcl_network_settings', array( $this, 'save_network_settings' ) );
		}
		
		// Site Health
		require_once CLICUTCL_DIR . 'includes/admin/class-site-health.php';
		$site_health = new SiteHealth();
		$site_health->register();

		// Dashboard Widget
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
	}

	/**
	 * Add Dashboard Widget.
	 */
	public function add_dashboard_widget() {
		wp_add_dashboard_widget(
			'clicutcl_dashboard_status',
			__( 'ClickTrail Status', 'click-trail-handler' ),
			array( $this, 'display_dashboard_widget' )
		);
	}

	/**
	 * Display Dashboard Widget content.
	 */
	public function display_dashboard_widget() {
		$options = get_option( 'clicutcl_attribution_settings', array() );
		$js_enabled = isset( $options['enable_js_injection'] ) ? $options['enable_js_injection'] : 1;
		$link_decor = isset( $options['enable_link_decoration'] ) ? $options['enable_link_decoration'] : 0;
		$domains = isset( $options['link_allowed_domains'] ) ? $options['link_allowed_domains'] : '';
		$domain_count = $domains ? count( array_filter( explode( ',', $domains ) ) ) : 0;
		
		// Cookie check (server-side only)
		$cookie_name = 'attribution'; // Default
		$cookie_status = isset( $_COOKIE[$cookie_name] ) ? '✅ Detected' : '❌ Not Detected (Visit site with UTMs)';
		
		// Caching check
		$caching = 'None Detected';
		if ( defined('WP_ROCKET_VERSION') || defined('LSCWP_V') || defined('WPCACHEHOME') || defined('AUTOPTIMIZE_PLUGIN_VERSION') ) {
			$caching = '⚠️ Caching Plugin Detected';
		}

		echo '<div class="clicutcl-widget-content">';
		echo '<table class="widefat" style="border:0;box-shadow:none;">';
		echo '<tr><td><strong>' . esc_html__( 'Attribution Cookie', 'click-trail-handler' ) . '</strong></td><td>' . esc_html( $cookie_status ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Caching Status', 'click-trail-handler' ) . '</strong></td><td>' . esc_html( $caching ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'JS Injection', 'click-trail-handler' ) . '</strong></td><td>' . ( $js_enabled ? '✅ On' : '❌ Off' ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Link Decoration', 'click-trail-handler' ) . '</strong></td><td>' . ( $link_decor ? '✅ On (' . intval( $domain_count ) . ' domains)' : '❌ Off' ) . '</td></tr>';
		echo '</table>';
		echo '<p style="text-align:right;margin-top:10px;"><a href="' . esc_url( admin_url( 'site-health.php?tab=status' ) ) . '">' . esc_html__( 'Run Full Diagnostics', 'click-trail-handler' ) . ' &rarr;</a></p>';
		echo '</div>';
	}

	/**
	 * Add admin menu.
	 */
	public function admin_menu() {
		add_menu_page(
			__( 'ClickTrail', 'click-trail-handler' ),
			__( 'ClickTrail', 'click-trail-handler' ),
			'manage_options',
			'clicutcl-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-chart-area', // Attribution friendly icon
			56 // Analytics plugin zone (after Plugins, near Yoast/MonsterInsights)
		);

		// Override the first submenu item to be "Settings" instead of repeating "ClickTrail"
		add_submenu_page(
			'clicutcl-settings',
			__( 'Settings', 'click-trail-handler' ),
			__( 'Settings', 'click-trail-handler' ),
			'manage_options',
			'clicutcl-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'clicutcl-settings',
			__( 'Logs', 'click-trail-handler' ),
			__( 'Logs', 'click-trail-handler' ),
			'manage_options',
			'clicutcl-logs',
			array( $this, 'logs_page' )
		);

		add_submenu_page(
			'clicutcl-settings',
			__( 'Diagnostics', 'click-trail-handler' ),
			__( 'Diagnostics', 'click-trail-handler' ),
			'manage_options',
			'clicutcl-diagnostics',
			array( $this, 'diagnostics_page' )
		);
	}

	/**
	 * Enqueue admin assets (conditional loading).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		$is_plugin_screen      = strpos( (string) $hook, 'clicutcl' ) !== false;
		$is_site_health_screen = ( 'site-health.php' === $hook );
		$debug_until           = get_transient( 'clicutcl_debug_until' );
		$debug_active          = $debug_until && (int) $debug_until > time();

		// SiteHealth ping script should only run on plugin screens and Site Health.
		if ( ( $is_plugin_screen || $is_site_health_screen ) && current_user_can( 'manage_options' ) ) {
			wp_register_script(
				'clicutcl-admin-sitehealth',
				CLICUTCL_URL . 'assets/js/admin-sitehealth.js',
				array(),
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' )
			);
			wp_enqueue_script( 'clicutcl-admin-sitehealth' );
			wp_localize_script(
				'clicutcl-admin-sitehealth',
				'clicutclSiteHealth',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'clicutcl_sitehealth' ),
					'debug'   => (bool) $debug_active,
				)
			);
		}

		// Only load plugin admin CSS/diagnostics on plugin screens.
		if ( ! $is_plugin_screen ) {
			return;
		}

		wp_enqueue_style(
			'clicutcl-admin',
			CLICUTCL_URL . 'assets/css/admin.css',
			array(),
			CLICUTCL_VERSION
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing context.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing context.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 'clicutcl-settings' === $page && current_user_can( 'manage_options' ) ) {
			wp_register_script(
				'clicutcl-admin-settings',
				CLICUTCL_URL . 'assets/js/admin-settings.js',
				array(),
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' )
			);
			wp_enqueue_script( 'clicutcl-admin-settings' );
		}

		if ( strpos( $hook, 'clicutcl-diagnostics' ) !== false ) {
			wp_register_script(
				'clicutcl-admin-diagnostics',
				CLICUTCL_URL . 'assets/js/admin-diagnostics.js',
				array(),
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' )
			);
			wp_enqueue_script( 'clicutcl-admin-diagnostics' );
			wp_localize_script(
				'clicutcl-admin-diagnostics',
				'clicutclDiagnostics',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'clicutcl_diag' ),
				)
			);
		}

		// Tracking v2 screen (Gutenberg-native UI).
		$tab_aliases = array( 'advanced' => 'trackingv2' );
		if ( isset( $tab_aliases[ $tab ] ) ) {
			$tab = $tab_aliases[ $tab ];
		}
		if ( 'clicutcl-settings' === $page && 'trackingv2' === $tab && current_user_can( 'manage_options' ) ) {
			wp_register_script(
				'clicutcl-admin-tracking-v2',
				CLICUTCL_URL . 'assets/js/admin-tracking-v2.js',
				array( 'wp-element', 'wp-components', 'wp-i18n' ),
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' )
			);
			wp_enqueue_script( 'clicutcl-admin-tracking-v2' );

			$settings = class_exists( 'CLICUTCL\\Tracking\\Settings' )
				? \CLICUTCL\Tracking\Settings::get_for_admin()
				: array();

			wp_localize_script(
				'clicutcl-admin-tracking-v2',
				'clicutclTrackingV2Config',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'clicutcl_tracking_v2' ),
					'settings'  => $settings,
				)
			);
		}
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// 1. Attribution Settings (General & WhatsApp)
		register_setting(
			'clicutcl_attribution_settings',
			'clicutcl_attribution_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_attribution_settings_defaults(),
			)
		);
		register_setting( 'clicutcl_tracking_v2', 'clicutcl_tracking_v2', array( 'CLICUTCL\\Tracking\\Settings', 'sanitize' ) );

		// Core Attribution Section
		add_settings_section(
			'clicutcl_core_section',
			__( 'Core Attribution Settings', 'click-trail-handler' ),
			null,
			'clicutcl_general_tab'
		);

		add_settings_field(
			'enable_attribution',
			__( 'Enable attribution tracking', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_core_section',
			array(
				'label_for' => 'enable_attribution',
				'option_name' => 'clicutcl_attribution_settings',
				'description' => __( 'Capture campaign and referral data for each visit.', 'click-trail-handler' ),
			)
		);

		add_settings_field(
			'cookie_days',
			__( 'Attribution retention (days)', 'click-trail-handler' ),
			array( $this, 'render_number_field' ),
			'clicutcl_general_tab',
			'clicutcl_core_section',
			array(
				'label_for'   => 'cookie_days',
				'option_name' => 'clicutcl_attribution_settings',
				'description' => __( 'How long attribution data should be stored.', 'click-trail-handler' ),
			)
		);

		// Reliability Section
		add_settings_section(
			'clicutcl_reliability_section',
			__( 'Reliability Settings', 'click-trail-handler' ),
			null,
			'clicutcl_general_tab'
		);

		add_settings_field(
			'enable_js_injection',
			__( 'Client-side capture fallback', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_reliability_section',
			array(
				'label_for' => 'enable_js_injection',
				'option_name' => 'clicutcl_attribution_settings',
				'class' => 'clicutcl-field-enable-js-injection',
				'description' => __( 'Recommended for cached or highly optimized pages.', 'click-trail-handler' ),
			)
		);

		add_settings_field(
			'inject_mutation_observer',
			__( 'Watch dynamic content', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_reliability_section',
			array(
				'label_for' => 'inject_mutation_observer',
				'option_name' => 'clicutcl_attribution_settings',
				'class' => 'clicutcl-field-inject-mutation-observer',
				'description' => __( 'Recommended when forms or links appear after page load.', 'click-trail-handler' ),
			)
		);

		// Cross-domain Section
		add_settings_section(
			'clicutcl_cross_domain_section',
			__( 'Cross-domain Settings', 'click-trail-handler' ),
			null,
			'clicutcl_general_tab'
		);

		add_settings_field(
			'enable_link_decoration',
			__( 'Decorate outgoing links', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_cross_domain_section',
			array(
				'label_for' => 'enable_link_decoration',
				'option_name' => 'clicutcl_attribution_settings',
				'class' => 'clicutcl-field-enable-link-decoration',
				'description' => __( 'Append attribution parameters to approved links.', 'click-trail-handler' ),
			)
		);

		add_settings_field(
			'link_allowed_domains',
			__( 'Allowed cross-domain destinations', 'click-trail-handler' ),
			array( $this, 'render_text_field' ),
			'clicutcl_general_tab',
			'clicutcl_cross_domain_section',
			array(
				'label_for' => 'link_allowed_domains',
				'option_name' => 'clicutcl_attribution_settings',
				'class' => 'clicutcl-field-link-allowed-domains',
				'description' => __( 'Domains where attribution parameters may be added.', 'click-trail-handler' ),
				'placeholder' => 'app.example.com, checkout.example.com',
			)
		);

		add_settings_field(
			'link_skip_signed',
			__( 'Do not modify signed URLs', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_cross_domain_section',
			array(
				'label_for' => 'link_skip_signed',
				'option_name' => 'clicutcl_attribution_settings',
				'class' => 'clicutcl-field-link-skip-signed',
				'description' => __( 'Recommended when links contain temporary signatures or protected access tokens.', 'click-trail-handler' ),
			)
		);

		add_settings_field(
			'enable_cross_domain_token',
			__( 'Pass cross-domain attribution token', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_cross_domain_section',
			array(
				'label_for' => 'enable_cross_domain_token',
				'option_name' => 'clicutcl_attribution_settings',
				'class' => 'clicutcl-field-enable-cross-domain-token',
				'description' => __( 'Adds a temporary token to preserve attribution across approved domains. No personal data is included.', 'click-trail-handler' ),
			)
		);

		// Advanced Section
		add_settings_section(
			'clicutcl_advanced_section',
			__( 'Advanced Settings', 'click-trail-handler' ),
			null,
			'clicutcl_general_tab'
		);

		add_settings_field(
			'inject_overwrite',
			__( 'Replace existing attribution values', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_advanced_section',
			array(
				'label_for'   => 'inject_overwrite',
				'option_name' => 'clicutcl_attribution_settings',
				'class'       => 'clicutcl-field-inject-overwrite',
				'description' => __( 'Use newly detected values even if attribution was already stored.', 'click-trail-handler' ),
			)
		);


		// WhatsApp Section
		add_settings_section(
			'clicutcl_whatsapp_section',
			__( 'WhatsApp Tracking', 'click-trail-handler' ),
			null,
			'clicutcl_whatsapp_tab'
		);

		add_settings_field(
			'enable_whatsapp',
			__( 'Enable WhatsApp tracking', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_whatsapp_tab',
			'clicutcl_whatsapp_section',
			array(
				'label_for' => 'enable_whatsapp',
				'option_name' => 'clicutcl_attribution_settings',
				'class' => 'clicutcl-field-enable-whatsapp',
				'description' => __( 'Track clicks on WhatsApp links and buttons.', 'click-trail-handler' ),
			)
		);

		add_settings_field(
			'whatsapp_append_attribution',
			__( 'Append attribution to message', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_whatsapp_tab',
			'clicutcl_whatsapp_section',
			array(
				'label_for' => 'whatsapp_append_attribution',
				'option_name' => 'clicutcl_attribution_settings',
				'class' => 'clicutcl-field-whatsapp-append-attribution',
				'description' => __( 'Add attribution details to the pre-filled WhatsApp message.', 'click-trail-handler' ),
			)
		);

		// 2. Consent Mode Settings
		add_settings_section(
			'clicutcl_consent_section',
			__( 'Consent Mode Configuration', 'click-trail-handler' ),
			null,
			'clicutcl_consent_mode'
		);

		add_settings_field(
			'enabled',
			__( 'Enable consent mode', 'click-trail-handler' ),
			array( $this, 'render_consent_checkbox' ),
			'clicutcl_consent_mode',
			'clicutcl_consent_section',
			array(
				'label_for' => 'enabled',
				'class'     => 'clicutcl-field-consent-enabled',
			)
		);

		add_settings_field(
			'regions',
			__( 'Regions requiring consent', 'click-trail-handler' ),
			array( $this, 'render_regions_field' ),
			'clicutcl_consent_mode',
			'clicutcl_consent_section',
			array(
				'label_for' => 'regions',
				'class'     => 'clicutcl-field-consent-regions',
			)
		);

		add_settings_field(
			'mode',
			__( 'Consent behavior', 'click-trail-handler' ),
			array( $this, 'render_consent_mode_field' ),
			'clicutcl_consent_mode',
			'clicutcl_consent_section',
			array(
				'label_for' => 'mode',
				'class'     => 'clicutcl-field-consent-mode',
			)
		);

		add_settings_field(
			'cmp_source',
			__( 'Consent source', 'click-trail-handler' ),
			array( $this, 'render_cmp_source_field' ),
			'clicutcl_consent_mode',
			'clicutcl_consent_section',
			array(
				'label_for' => 'cmp_source',
				'class'     => 'clicutcl-field-consent-cmp-source',
			)
		);

		add_settings_field(
			'cmp_timeout_ms',
			__( 'Consent wait time (ms)', 'click-trail-handler' ),
			array( $this, 'render_cmp_timeout_field' ),
			'clicutcl_consent_mode',
			'clicutcl_consent_section',
			array(
				'label_for' => 'cmp_timeout_ms',
				'class'     => 'clicutcl-field-consent-timeout',
			)
		);

		// 3. GTM Settings
		add_settings_section(
			'clicutcl_gtm_section',
			__( 'Google Tag Manager', 'click-trail-handler' ),
			null,
			'clicutcl_gtm'
		);

		add_settings_field(
			'container_id',
			__( 'Container ID', 'click-trail-handler' ),
			array( $this, 'render_gtm_text_field' ),
			'clicutcl_gtm',
			'clicutcl_gtm_section',
			array(
				'label_for'   => 'container_id',
				'description' => __( 'Use only if your site does not already load Google Tag Manager.', 'click-trail-handler' ),
				'placeholder' => 'GTM-XXXXXXX',
			)
		);

		// 4. Server-side Settings
		register_setting( 'clicutcl_server_side', 'clicutcl_server_side', array( $this, 'sanitize_server_side_settings' ) );

		add_settings_section(
			'clicutcl_server_side_section',
			__( 'Server-side Transport', 'click-trail-handler' ),
			null,
			'clicutcl_server_side_tab'
		);

		add_settings_field(
			'enabled',
			__( 'Enable server-side delivery', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_server_side_tab',
			'clicutcl_server_side_section',
			array(
				'label_for'   => 'enabled',
				'option_name' => 'clicutcl_server_side',
				'description' => __( 'Send events through your own collector endpoint.', 'click-trail-handler' ),
			)
		);

		add_settings_field(
			'endpoint_url',
			__( 'Collector URL', 'click-trail-handler' ),
			array( $this, 'render_text_field' ),
			'clicutcl_server_side_tab',
			'clicutcl_server_side_section',
			array(
				'label_for'   => 'endpoint_url',
				'option_name' => 'clicutcl_server_side',
				'description' => __( 'Endpoint that receives server-side events.', 'click-trail-handler' ),
				'placeholder' => 'https://collect.example.com',
			)
		);

		add_settings_field(
			'adapter',
			__( 'Delivery adapter', 'click-trail-handler' ),
			array( $this, 'render_select_field' ),
			'clicutcl_server_side_tab',
			'clicutcl_server_side_section',
			array(
				'label_for'   => 'adapter',
				'option_name' => 'clicutcl_server_side',
				'options'     => array(
					'generic'   => __( 'Generic Collector', 'click-trail-handler' ),
					'sgtm'      => __( 'sGTM (Server GTM)', 'click-trail-handler' ),
					'meta_capi' => __( 'Meta CAPI', 'click-trail-handler' ),
					'google_ads' => __( 'Google Ads / GA4', 'click-trail-handler' ),
					'linkedin_capi' => __( 'LinkedIn CAPI', 'click-trail-handler' ),
				),
			)
		);

		add_settings_field(
			'timeout',
			__( 'Request timeout (seconds)', 'click-trail-handler' ),
			array( $this, 'render_number_field' ),
			'clicutcl_server_side_tab',
			'clicutcl_server_side_section',
			array(
				'label_for'   => 'timeout',
				'option_name' => 'clicutcl_server_side',
			)
		);

		add_settings_field(
			'remote_failure_telemetry',
			__( 'Share anonymous failure counts', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_server_side_tab',
			'clicutcl_server_side_section',
			array(
				'label_for'   => 'remote_failure_telemetry',
				'option_name' => 'clicutcl_server_side',
				'description' => __( 'Only aggregated failure counts are shared. No payloads or personal data are included.', 'click-trail-handler' ),
			)
		);

		if ( is_multisite() && ! empty( Settings::get_network() ) ) {
			add_settings_field(
				'use_network',
				__( 'Use Network Defaults', 'click-trail-handler' ),
				array( $this, 'render_checkbox_field' ),
				'clicutcl_server_side_tab',
				'clicutcl_server_side_section',
				array(
					'label_for'   => 'use_network',
					'option_name' => 'clicutcl_server_side',
					'tooltip'     => __( 'Use network-level server-side settings for this site.', 'click-trail-handler' ),
				)
			);
		}
	}

	/**
	 * Render text field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_text_field( $args ) {
		$option_name = $args['option_name'];
		$options = get_option( $option_name );
		$value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : '';
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		?>
		<input type="text" name="<?php echo esc_attr( $option_name . '[' . $args['label_for'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text clicktrail-field-input" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
		<?php if ( $description ) : ?>
			<p class="description"><?php echo wp_kses_post( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render select field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_select_field( $args ) {
		$option_name = $args['option_name'];
		$options     = get_option( $option_name, array() );
		$current     = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : '';
		$choices     = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		$description = isset( $args['description'] ) ? $args['description'] : '';
		?>
		<select name="<?php echo esc_attr( $option_name . '[' . $args['label_for'] . ']' ); ?>" class="clicktrail-field-select">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( $description ) : ?>
			<p class="description"><?php echo wp_kses_post( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render settings page with tabs.
	 */
	public function render_settings_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page navigation does not require nonce.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		$legacy_map = array(
			'channels'     => 'whatsapp',
			'destinations' => 'server',
			'advanced'     => 'trackingv2',
		);
		if ( isset( $legacy_map[ $active_tab ] ) ) {
			$active_tab = $legacy_map[ $active_tab ];
		}

		$tabs = $this->get_settings_tabs();
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'general';
		}
		$tab_meta = $tabs[ $active_tab ];
		?>
		<div class="wrap clicktrail-settings-wrap">
			<div class="clicktrail-page-header">
				<div class="clicktrail-page-title">
					<span class="clicktrail-page-eyebrow"><?php esc_html_e( 'ClickTrail', 'click-trail-handler' ); ?></span>
					<h1><?php echo esc_html( $tab_meta['title'] ); ?></h1>
					<?php if ( ! empty( $tab_meta['description'] ) ) : ?>
						<p class="clicktrail-page-description"><?php echo esc_html( $tab_meta['description'] ); ?></p>
					<?php endif; ?>
				</div>
				<div class="clicktrail-page-meta">
					<span class="clicktrail-version-badge"><?php echo esc_html( 'v' . CLICUTCL_VERSION ); ?></span>
				</div>
			</div>

			<?php if ( 'general' === $active_tab ) : ?>
				<?php $this->render_settings_status_bar(); ?>
			<?php endif; ?>
			<?php settings_errors(); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a href="?page=clicutcl-settings&tab=<?php echo esc_attr( $slug ); ?>" class="nav-tab <?php echo esc_attr( $slug === $active_tab ? 'nav-tab-active' : '' ); ?>">
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
						<?php echo esc_html( $tab['label'] ); ?>
						<?php if ( ! empty( $tab['badge'] ) ) : ?>
							<span class="clicktrail-tab-badge"><?php echo esc_html( $tab['badge'] ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'trackingv2' === $active_tab ) : ?>
				<div class="clicktrail-inline-notice">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Advanced tracking configuration is managed in its dedicated editor. The rest of the settings screen uses the standard WordPress save flow.', 'click-trail-handler' ); ?></span>
				</div>
				<div id="clicutcl-tracking-v2-root"></div>
			<?php else : ?>
				<?php $form_config = $this->get_settings_form_config( $active_tab ); ?>
				<form action="options.php" method="post" class="clicktrail-settings-form">
					<?php
					settings_fields( $form_config['group'] );
					foreach ( $this->get_settings_cards( $active_tab ) as $card ) {
						$this->render_settings_card( $card );
					}
					$this->render_settings_save_bar();
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Return tab definitions for the settings screen.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_settings_tabs() {
		return array(
			'general'    => array(
				'label'       => __( 'Attribution', 'click-trail-handler' ),
				'title'       => __( 'Attribution', 'click-trail-handler' ),
				'description' => __( 'Capture and preserve source data such as UTM, referrer, and click identifiers.', 'click-trail-handler' ),
				'icon'        => 'dashicons-admin-generic',
			),
			'whatsapp'   => array(
				'label'       => __( 'WhatsApp', 'click-trail-handler' ),
				'title'       => __( 'WhatsApp', 'click-trail-handler' ),
				'description' => __( 'Control how attribution is carried into WhatsApp clicks and pre-filled messages.', 'click-trail-handler' ),
				'icon'        => 'dashicons-format-chat',
			),
			'consent'    => array(
				'label'       => __( 'Consent', 'click-trail-handler' ),
				'title'       => __( 'Consent', 'click-trail-handler' ),
				'description' => __( 'Control when attribution begins based on user consent and your CMP setup.', 'click-trail-handler' ),
				'icon'        => 'dashicons-privacy',
			),
			'gtm'        => array(
				'label'       => __( 'Integrations', 'click-trail-handler' ),
				'title'       => __( 'Integrations', 'click-trail-handler' ),
				'description' => __( 'Connect ClickTrail to external tools when your site needs them.', 'click-trail-handler' ),
				'icon'        => 'dashicons-chart-bar',
			),
			'server'     => array(
				'label'       => __( 'Server-side', 'click-trail-handler' ),
				'title'       => __( 'Server-side', 'click-trail-handler' ),
				'description' => __( 'Send events through your own endpoint when you need delivery outside the browser.', 'click-trail-handler' ),
				'icon'        => 'dashicons-cloud',
			),
			'trackingv2' => array(
				'label'       => __( 'Tracking', 'click-trail-handler' ),
				'title'       => __( 'Tracking', 'click-trail-handler' ),
				'description' => __( 'Manage advanced event delivery, destinations, and lifecycle controls.', 'click-trail-handler' ),
				'icon'        => 'dashicons-admin-tools',
				'badge'       => 'v2',
			),
		);
	}

	/**
	 * Return the settings group and option key for the active tab.
	 *
	 * @param string $active_tab Active tab slug.
	 * @return array<string, string>
	 */
	private function get_settings_form_config( $active_tab ) {
		switch ( $active_tab ) {
			case 'general':
			case 'whatsapp':
				return array(
					'group' => 'clicutcl_attribution_settings',
				);
			case 'consent':
				return array(
					'group' => 'clicutcl_consent_mode',
				);
			case 'gtm':
				return array(
					'group' => 'clicutcl_gtm',
				);
			case 'server':
			default:
				return array(
					'group' => 'clicutcl_server_side',
				);
		}
	}

	/**
	 * Return card metadata for the active tab.
	 *
	 * @param string $active_tab Active tab slug.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_settings_cards( $active_tab ) {
		switch ( $active_tab ) {
			case 'general':
				return array(
					array(
						'id'          => 'general-core',
						'page'        => 'clicutcl_general_tab',
						'section'     => 'clicutcl_core_section',
						'title'       => __( 'Core', 'click-trail-handler' ),
						'description' => __( 'High-confidence settings used on every site.', 'click-trail-handler' ),
						'icon'        => 'dashicons-chart-area',
					),
					array(
						'id'          => 'general-reliability',
						'page'        => 'clicutcl_general_tab',
						'section'     => 'clicutcl_reliability_section',
						'title'       => __( 'Reliability', 'click-trail-handler' ),
						'description' => __( 'Recommended settings to keep attribution working on cached pages and dynamic sites.', 'click-trail-handler' ),
						'icon'        => 'dashicons-shield-alt',
						'tag'         => __( 'Recommended', 'click-trail-handler' ),
						'tag_tone'    => 'recommended',
					),
					array(
						'id'          => 'general-cross-domain',
						'page'        => 'clicutcl_general_tab',
						'section'     => 'clicutcl_cross_domain_section',
						'title'       => __( 'Cross-domain', 'click-trail-handler' ),
						'description' => __( 'Preserve attribution when visitors move between your domains or subdomains.', 'click-trail-handler' ),
						'icon'        => 'dashicons-admin-links',
					),
					array(
						'id'          => 'general-advanced',
						'page'        => 'clicutcl_general_tab',
						'section'     => 'clicutcl_advanced_section',
						'title'       => __( 'Advanced technical options', 'click-trail-handler' ),
						'description' => __( 'Low-level behavior that most sites can leave unchanged.', 'click-trail-handler' ),
						'icon'        => 'dashicons-admin-tools',
						'collapsible' => true,
						'collapsed'   => true,
						'tag'         => __( 'Advanced', 'click-trail-handler' ),
						'tag_tone'    => 'muted',
					),
				);
			case 'whatsapp':
				return array(
					array(
						'id'          => 'whatsapp',
						'page'        => 'clicutcl_whatsapp_tab',
						'section'     => 'clicutcl_whatsapp_section',
						'title'       => __( 'WhatsApp attribution', 'click-trail-handler' ),
						'description' => __( 'Carry attribution into outbound WhatsApp entry points when you need it.', 'click-trail-handler' ),
						'icon'        => 'dashicons-format-chat',
					),
				);
			case 'consent':
				return array(
					array(
						'id'          => 'consent',
						'page'        => 'clicutcl_consent_mode',
						'section'     => 'clicutcl_consent_section',
						'title'       => __( 'Consent mode', 'click-trail-handler' ),
						'description' => __( 'Decide when attribution and event collection are allowed to start.', 'click-trail-handler' ),
						'icon'        => 'dashicons-shield-alt',
					),
				);
			case 'gtm':
				return array(
					array(
						'id'          => 'gtm',
						'page'        => 'clicutcl_gtm',
						'section'     => 'clicutcl_gtm_section',
						'title'       => __( 'Google Tag Manager', 'click-trail-handler' ),
						'description' => __( 'Use this only if your site does not already load GTM elsewhere.', 'click-trail-handler' ),
						'icon'        => 'dashicons-chart-bar',
					),
				);
			case 'server':
			default:
				return array(
					array(
						'id'          => 'server',
						'page'        => 'clicutcl_server_side_tab',
						'section'     => 'clicutcl_server_side_section',
						'title'       => __( 'Server-side delivery', 'click-trail-handler' ),
						'description' => __( 'Configure the endpoint and delivery behavior for server-side dispatch.', 'click-trail-handler' ),
						'icon'        => 'dashicons-cloud',
					),
				);
		}
	}

	/**
	 * Render the top summary pills for the settings page.
	 *
	 * @return void
	 */
	private function render_settings_status_bar() {
		$attr_options    = get_option( 'clicutcl_attribution_settings', array() );
		$server_options  = Settings::get();
		$consent_obj     = new Consent_Mode_Settings();
		$consent_enabled = $consent_obj->is_consent_mode_enabled();
		$server_enabled  = ! empty( $server_options['enabled'] );
		$cross_domain_on = ! empty( $attr_options['enable_link_decoration'] ) || ! empty( $attr_options['enable_cross_domain_token'] );
		?>
		<div class="clicktrail-summary-bar">
			<?php
			$this->render_status_pill(
				! empty( $attr_options['enable_attribution'] ) ? 'success' : 'neutral',
				__( 'Attribution', 'click-trail-handler' ),
				! empty( $attr_options['enable_attribution'] ) ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' )
			);
			$this->render_status_pill(
				$consent_enabled ? 'success' : 'neutral',
				__( 'Consent Mode', 'click-trail-handler' ),
				$consent_enabled ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' )
			);
			$this->render_status_pill(
				! empty( $attr_options['enable_js_injection'] ) ? 'success' : 'neutral',
				__( 'JS Capture', 'click-trail-handler' ),
				! empty( $attr_options['enable_js_injection'] ) ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' )
			);
			$this->render_status_pill(
				$cross_domain_on ? 'info' : 'neutral',
				__( 'Cross-domain', 'click-trail-handler' ),
				$cross_domain_on ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' )
			);
			$this->render_status_pill(
				$server_enabled ? 'success' : 'neutral',
				__( 'Server-side', 'click-trail-handler' ),
				$server_enabled ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' )
			);
			?>
		</div>
		<?php
	}

	/**
	 * Render a single summary pill.
	 *
	 * @param string $tone  Visual tone.
	 * @param string $title Label title.
	 * @param string $text  Detail text.
	 * @return void
	 */
	private function render_status_pill( $tone, $title, $text ) {
		?>
		<div class="clicktrail-status-pill clicktrail-status-pill--<?php echo esc_attr( $tone ); ?>">
			<span class="clicktrail-status-pill__dot" aria-hidden="true"></span>
			<span class="clicktrail-status-pill__text">
				<strong><?php echo esc_html( $title ); ?>:</strong>
				<?php echo esc_html( $text ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Render a registered settings section inside a card shell.
	 *
	 * @param array $card Card definition.
	 * @return void
	 */
	private function render_settings_card( $card ) {
		$collapsible = ! empty( $card['collapsible'] );
		$collapsed   = ! empty( $card['collapsed'] );
		$classes     = array( 'clicktrail-card' );
		$header_cls  = $collapsible ? 'clicktrail-card__header' : 'clicktrail-card__header clicktrail-card__header--static';

		if ( $collapsible ) {
			$classes[] = 'clicktrail-card--collapsible';
		}
		if ( $collapsed ) {
			$classes[] = 'is-collapsed';
		}
		?>
		<section class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" id="clicktrail-card-<?php echo esc_attr( $card['id'] ); ?>">
			<?php if ( $collapsible ) : ?>
				<button
					type="button"
					class="<?php echo esc_attr( $header_cls ); ?>"
					data-clicktrail-card-toggle="1"
					aria-expanded="<?php echo esc_attr( $collapsed ? 'false' : 'true' ); ?>"
				>
			<?php else : ?>
				<div class="<?php echo esc_attr( $header_cls ); ?>">
			<?php endif; ?>
				<span class="clicktrail-card__header-main">
					<span class="clicktrail-card__icon dashicons <?php echo esc_attr( $card['icon'] ); ?>" aria-hidden="true"></span>
					<span class="clicktrail-card__heading">
						<span class="clicktrail-card__title"><?php echo esc_html( $card['title'] ); ?></span>
						<?php if ( ! empty( $card['description'] ) ) : ?>
							<span class="clicktrail-card__description"><?php echo esc_html( $card['description'] ); ?></span>
						<?php endif; ?>
					</span>
				</span>
				<span class="clicktrail-card__meta">
					<?php if ( ! empty( $card['tag'] ) ) : ?>
						<span class="clicktrail-card__tag <?php echo ! empty( $card['tag_tone'] ) ? esc_attr( 'clicktrail-card__tag--' . $card['tag_tone'] ) : ''; ?>"><?php echo esc_html( $card['tag'] ); ?></span>
					<?php endif; ?>
					<?php if ( $collapsible ) : ?>
						<span class="clicktrail-card__chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					<?php endif; ?>
				</span>
			<?php if ( $collapsible ) : ?>
				</button>
			<?php else : ?>
				</div>
			<?php endif; ?>
			<div class="clicktrail-card__body">
				<table class="form-table clicktrail-card__table" role="presentation">
					<?php do_settings_fields( $card['page'], $card['section'] ); ?>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the sticky save bar for a settings form.
	 *
	 * @return void
	 */
	private function render_settings_save_bar() {
		?>
		<div class="clicktrail-save-bar">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'click-trail-handler' ); ?></button>
			<span class="clicktrail-save-bar__hint">
				<?php esc_html_e( 'Save changes for this tab.', 'click-trail-handler' ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Render checkbox field as modern toggle switch.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_checkbox_field( $args ) {
		$option_name = $args['option_name'];
		$options = get_option( $option_name );
		$value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : 0;
		$tooltip = isset( $args['tooltip'] ) ? $args['tooltip'] : '';
		$field_name = $option_name . '[' . $args['label_for'] . ']';
		?>
		<div class="clicktrail-toggle-wrapper">
			<label class="clicktrail-toggle">
				<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="0" />
				<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" value="1" <?php checked( 1, $value ); ?> />
				<span class="clicktrail-toggle-slider"></span>
			</label>
			<?php if ( $tooltip ) : ?>
				<span class="clicktrail-help-tip" data-tip="<?php echo esc_attr( $tooltip ); ?>">?</span>
			<?php endif; ?>
		</div>
		<?php if ( isset( $args['description'] ) ) : ?>
			<p class="clicktrail-description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_number_field( $args ) {
		$option_name = $args['option_name'];
		$options = get_option( $option_name );
		$value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : '';
		$description = isset( $args['description'] ) ? $args['description'] : '';
		?>
		<input type="number" name="<?php echo esc_attr( $option_name . '[' . $args['label_for'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text clicktrail-field-input clicktrail-field-input--narrow" />
		<?php if ( $description ) : ?>
			<p class="description"><?php echo wp_kses_post( $description ); ?></p>
		<?php endif; ?>
		<?php
	}
	
	public function render_gtm_text_field( $args ) {
		$settings = new GTM_Settings();
		$value = $settings->get();
		$id = isset($value['container_id']) ? $value['container_id'] : '';
		$description = isset( $args['description'] ) ? $args['description'] : '';
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : 'GTM-XXXXXXX';
		?>
		<input type="text" name="clicutcl_gtm[container_id]" value="<?php echo esc_attr($id); ?>" class="regular-text clicktrail-field-input clicktrail-field-input--mono" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
		<?php if ( $description ) : ?>
			<p class="description"><?php echo wp_kses_post( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function sanitize_settings( $input ) {
		$current  = get_option( 'clicutcl_attribution_settings', array() );
		$defaults = $this->get_attribution_settings_defaults();
		if ( ! current_user_can( 'manage_options' ) ) {
			return is_array( $current ) ? array_merge( $defaults, $current ) : $defaults;
		}

		$input   = $this->normalize_settings_input( $input );
		$schema  = $this->get_attribution_settings_schema();
		$current = is_array( $current ) ? $current : array();
		$merged  = array_merge( $defaults, $current );

		foreach ( $schema as $key => $rule ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$sanitizer = isset( $rule['sanitize'] ) ? $rule['sanitize'] : null;
			if ( is_callable( $sanitizer ) ) {
				$merged[ $key ] = call_user_func( $sanitizer, $input[ $key ] );
			}
		}

		return $merged;
	}

	/**
	 * Canonical schema for clicutcl_attribution_settings.
	 *
	 * @return array
	 */
	private function get_attribution_settings_schema() {
		return array(
			'enable_attribution'         => array(
				'default'  => 1,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'cookie_days'                => array(
				'default'  => 90,
				'sanitize' => array( $this, 'sanitize_cookie_days' ),
			),
			'enable_js_injection'        => array(
				'default'  => 1,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'inject_overwrite'           => array(
				'default'  => 0,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'inject_mutation_observer'   => array(
				'default'  => 1,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'inject_observer_target'     => array(
				'default'  => 'body',
				'sanitize' => array( $this, 'sanitize_observer_target' ),
			),
			'enable_link_decoration'     => array(
				'default'  => 0,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'link_allowed_domains'       => array(
				'default'  => '',
				'sanitize' => array( $this, 'sanitize_domains_csv' ),
			),
			'link_skip_signed'           => array(
				'default'  => 1,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'enable_cross_domain_token'  => array(
				'default'  => 0,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'enable_whatsapp'            => array(
				'default'  => 1,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'whatsapp_append_attribution' => array(
				'default'  => 0,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			// Backward compatibility for legacy keys still read in some installs.
			'enable_consent_banner'      => array(
				'default'  => 0,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'require_consent'            => array(
				'default'  => 1,
				'sanitize' => array( $this, 'sanitize_toggle' ),
			),
			'consent_mode_region'        => array(
				'default'  => '',
				'sanitize' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Return the canonical defaults for clicutcl_attribution_settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_attribution_settings_defaults() {
		$defaults = array();
		$schema   = $this->get_attribution_settings_schema();

		foreach ( $schema as $key => $rule ) {
			$defaults[ $key ] = isset( $rule['default'] ) ? $rule['default'] : '';
		}

		return $defaults;
	}

	/**
	 * Normalize posted settings payload into scalar values the schema expects.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	private function normalize_settings_input( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$input      = wp_unslash( $input );
		$normalized = array();

		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) ) {
				$candidate = end( $value );
				$value     = false !== $candidate ? $candidate : '';
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * Sanitize checkbox/toggle values to 0|1.
	 *
	 * @param mixed $value Raw input.
	 * @return int
	 */
	private function sanitize_toggle( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return 0;
		}

		$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		return true === $parsed ? 1 : 0;
	}

	/**
	 * Sanitize cookie retention days.
	 *
	 * @param mixed $value Raw input.
	 * @return int
	 */
	private function sanitize_cookie_days( $value ) {
		$days = absint( $value );
		if ( $days < 1 ) {
			$days = 90;
		}

		return min( 3650, $days );
	}

	/**
	 * Sanitize CSS selector for injection observer target.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	private function sanitize_observer_target( $value ) {
		$selector = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $selector ) {
			return 'body';
		}

		if ( strlen( $selector ) > 120 ) {
			$selector = substr( $selector, 0, 120 );
		}

		return $selector;
	}

	/**
	 * Normalize domains list into a comma-separated canonical string.
	 *
	 * @param mixed $value Raw domains value.
	 * @return string
	 */
	private function sanitize_domains_csv( $value ) {
		$raw = is_array( $value ) ? $value : preg_split( '/[\r\n,\s]+/', (string) $value );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();

		foreach ( $raw as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}

			$item = preg_replace( '#^https?://#i', '', $item );
			$item = preg_replace( '#/.*$#', '', $item );
			$item = strtolower( trim( $item ) );

			if ( preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $item ) ) {
				$out[] = $item;
			}
		}

		$out = array_values( array_unique( $out ) );
		return implode( ',', $out );
	}

	public function sanitize_server_side_settings( $input ) {
		$current = is_network_admin() ? Settings::get_network() : get_option( 'clicutcl_server_side', array() );
		$current = is_array( $current ) ? $current : array();
		$input   = is_array( $input ) ? wp_unslash( $input ) : array();

		$new_input = array_merge(
			array(
				'enabled'                  => 0,
				'endpoint_url'             => '',
				'adapter'                  => 'generic',
				'timeout'                  => 5,
				'use_network'              => 0,
				'remote_failure_telemetry' => 0,
			),
			$current
		);

		$new_input['enabled'] = isset( $input['enabled'] ) ? absint( $input['enabled'] ) : 0;

		if ( isset( $input['endpoint_url'] ) ) {
			$new_input['endpoint_url'] = esc_url_raw( trim( (string) $input['endpoint_url'] ) );
		}

		if ( isset( $input['adapter'] ) ) {
			$adapter = sanitize_key( $input['adapter'] );
			$new_input['adapter'] = isset( \CLICUTCL\Server_Side\Dispatcher::ALLOWED_ADAPTERS[ $adapter ] ) ? $adapter : 'generic';
		}

		if ( isset( $input['timeout'] ) ) {
			$timeout = absint( $input['timeout'] );
			$new_input['timeout'] = $timeout > 0 ? min( 15, $timeout ) : 5;
		}

		if ( isset( $input['use_network'] ) ) {
			$new_input['use_network'] = absint( $input['use_network'] );
		}

		$new_input['remote_failure_telemetry'] = isset( $input['remote_failure_telemetry'] ) ? absint( $input['remote_failure_telemetry'] ) : 0;

		return $new_input;
	}
}
