<?php
/**
 * ClickTrail Admin Settings
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

use CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings;
use CLICUTCL\Modules\GTM\GTM_Settings;
use CLICUTCL\Server_Side\Dispatcher;
use CLICUTCL\Server_Side\Queue;
use CLICUTCL\Server_Side\Settings;
use CLICUTCL\Tracking\Settings as Tracking_Settings;

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
		add_action( 'wp_ajax_clicutcl_get_admin_settings', array( $this, 'ajax_get_admin_settings' ) );
		add_action( 'wp_ajax_clicutcl_save_admin_settings', array( $this, 'ajax_save_admin_settings' ) );
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
		echo '<tr><td><strong>' . esc_html__( 'Form Capture Fallback', 'click-trail-handler' ) . '</strong></td><td>' . ( $js_enabled ? '✅ On' : '❌ Off' ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Cross-domain Links', 'click-trail-handler' ) . '</strong></td><td>' . ( $link_decor ? '✅ On (' . intval( $domain_count ) . ' domains)' : '❌ Off' ) . '</td></tr>';
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
			array( $this, 'render_settings_app_page' ),
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
			array( $this, 'render_settings_app_page' )
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

		// Unified settings app.
		if ( 'clicutcl-settings' === $page && current_user_can( 'manage_options' ) ) {
			wp_register_script(
				'clicutcl-admin-settings-app',
				CLICUTCL_URL . 'assets/js/admin-settings-app.js',
				array( 'wp-element', 'wp-components', 'wp-i18n' ),
				CLICUTCL_VERSION,
				\clicutcl_script_args( true, 'defer' )
			);
			wp_enqueue_script( 'clicutcl-admin-settings-app' );

			wp_localize_script(
				'clicutcl-admin-settings-app',
				'clicutclAdminSettingsConfig',
				$this->get_settings_app_config()
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


		// WhatsApp Section (on Attribution tab, same option group)
		add_settings_section(
			'clicutcl_whatsapp_section',
			__( 'WhatsApp', 'click-trail-handler' ),
			null,
			'clicutcl_general_tab'
		);

		add_settings_field(
			'enable_whatsapp',
			__( 'Enable WhatsApp tracking', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
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
			'clicutcl_general_tab',
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
	 * Render the unified settings app shell.
	 *
	 * @return void
	 */
	public function render_settings_app_page() {
		?>
		<div class="wrap clicktrail-settings-wrap">
			<div id="clicutcl-admin-settings-root"></div>
			<noscript>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'ClickTrail settings require JavaScript in wp-admin.', 'click-trail-handler' ); ?></p>
				</div>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Return tab definitions for the unified settings app.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_settings_app_tabs() {
		return array(
			'capture'  => array(
				'label'       => __( 'Capture', 'click-trail-handler' ),
				'title'       => __( 'Capture', 'click-trail-handler' ),
				'description' => __( 'Capture and preserve source data from campaigns, referrals, and ad clicks.', 'click-trail-handler' ),
				'icon'        => 'dashicons-chart-area',
			),
			'forms'    => array(
				'label'       => __( 'Forms', 'click-trail-handler' ),
				'title'       => __( 'Forms', 'click-trail-handler' ),
				'description' => __( 'Keep attribution attached to forms, lead sources, and messaging touchpoints.', 'click-trail-handler' ),
				'icon'        => 'dashicons-feedback',
			),
			'events'   => array(
				'label'       => __( 'Events', 'click-trail-handler' ),
				'title'       => __( 'Events', 'click-trail-handler' ),
				'description' => __( 'Configure browser event collection, destinations, and the unified event pipeline.', 'click-trail-handler' ),
				'icon'        => 'dashicons-share',
			),
			'delivery' => array(
				'label'       => __( 'Delivery', 'click-trail-handler' ),
				'title'       => __( 'Delivery', 'click-trail-handler' ),
				'description' => __( 'Control privacy, server-side transport, and operational safeguards.', 'click-trail-handler' ),
				'icon'        => 'dashicons-cloud',
			),
		);
	}

	/**
	 * Resolve the active tab while preserving legacy URLs.
	 *
	 * @return array<string, mixed>
	 */
	private function resolve_settings_app_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page navigation does not require nonce.
		$raw_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'capture';
		$aliases = array(
			'general'      => 'capture',
			'attribution'  => 'capture',
			'whatsapp'     => 'forms',
			'channels'     => 'forms',
			'gtm'          => 'events',
			'integrations' => 'events',
			'tracking'     => 'events',
			'trackingv2'   => 'events',
			'advanced'     => 'events',
			'server'       => 'delivery',
			'server-side'  => 'delivery',
			'consent'      => 'delivery',
			'privacy'      => 'delivery',
			'destinations' => 'delivery',
		);
		$tabs    = $this->get_settings_app_tabs();
		$active  = isset( $aliases[ $raw_tab ] ) ? $aliases[ $raw_tab ] : $raw_tab;

		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'capture';
		}

		return array(
			'raw_tab'      => $raw_tab,
			'active_tab'   => $active,
			'used_legacy'  => isset( $aliases[ $raw_tab ] ),
			'migration_ui' => 'trackingv2' === $raw_tab,
		);
	}

	/**
	 * Build the localized config for the unified settings app.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings_app_config() {
		$resolved = $this->resolve_settings_app_tab();

		return array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'clicutcl_admin_settings' ),
			'pageTitle'       => __( 'ClickTrail', 'click-trail-handler' ),
			'activeTab'       => $resolved['active_tab'],
			'legacyTab'       => $resolved['raw_tab'],
			'migrationNotice' => $resolved['migration_ui']
				? __( 'These settings are now organized by capability. Browser events and destinations live under Events, while transport and privacy controls live under Delivery.', 'click-trail-handler' )
				: '',
			'tabs'            => $this->get_settings_app_tabs(),
			'settings'        => $this->get_unified_admin_settings(),
		);
	}

	/**
	 * Return grouped settings data for the unified admin app.
	 *
	 * @return array<string, mixed>
	 */
	private function get_unified_admin_settings() {
		$attr_defaults     = $this->get_attribution_settings_defaults();
		$attr_options      = get_option( 'clicutcl_attribution_settings', array() );
		$attr_options      = is_array( $attr_options ) ? array_merge( $attr_defaults, $attr_options ) : $attr_defaults;
		$consent_settings  = ( new Consent_Mode_Settings() )->get();
		$gtm_settings      = ( new GTM_Settings() )->get();
		$tracking_settings = class_exists( 'CLICUTCL\\Tracking\\Settings' ) ? Tracking_Settings::get_for_admin() : Tracking_Settings::defaults();
		$server_effective  = Settings::get();
		$server_site       = get_option( Settings::OPTION_SITE, array() );
		$server_site       = is_array( $server_site ) ? $server_site : array();
		$server_network    = Settings::get_network();
		$has_network       = is_multisite() && ! empty( $server_network );
		$use_network       = $has_network ? ( ! isset( $server_site['use_network'] ) || 1 === (int) $server_site['use_network'] ) : false;
		$feature_flags     = isset( $tracking_settings['feature_flags'] ) && is_array( $tracking_settings['feature_flags'] ) ? $tracking_settings['feature_flags'] : array();
		$destinations      = isset( $tracking_settings['destinations'] ) && is_array( $tracking_settings['destinations'] ) ? $tracking_settings['destinations'] : array();
		$providers         = isset( $tracking_settings['external_forms']['providers'] ) && is_array( $tracking_settings['external_forms']['providers'] ) ? $tracking_settings['external_forms']['providers'] : array();
		$lifecycle         = isset( $tracking_settings['lifecycle']['crm_ingestion'] ) && is_array( $tracking_settings['lifecycle']['crm_ingestion'] ) ? $tracking_settings['lifecycle']['crm_ingestion'] : array();
		$security          = isset( $tracking_settings['security'] ) && is_array( $tracking_settings['security'] ) ? $tracking_settings['security'] : array();
		$diagnostics       = isset( $tracking_settings['diagnostics'] ) && is_array( $tracking_settings['diagnostics'] ) ? $tracking_settings['diagnostics'] : array();
		$dedup             = isset( $tracking_settings['dedup'] ) && is_array( $tracking_settings['dedup'] ) ? $tracking_settings['dedup'] : array();
		$cross_domain_on   = ! empty( $attr_options['enable_link_decoration'] ) || ! empty( $attr_options['enable_cross_domain_token'] );
		$providers_active  = false;

		foreach ( $providers as $provider_row ) {
			if ( ! empty( $provider_row['enabled'] ) ) {
				$providers_active = true;
				break;
			}
		}

		$forms_active  = ! empty( $attr_options['enable_js_injection'] ) || ! empty( $attr_options['enable_whatsapp'] ) || $providers_active;
		$events_active = ! empty( $feature_flags['event_v2'] );

		return array(
			'capture'  => array(
				'enabled'          => ! empty( $attr_options['enable_attribution'] ) ? 1 : 0,
				'retention_days'   => absint( $attr_options['cookie_days'] ?? 90 ),
				'decorate_links'   => ! empty( $attr_options['enable_link_decoration'] ) ? 1 : 0,
				'allowed_domains'  => $this->format_multiline_setting( $attr_options['link_allowed_domains'] ?? '' ),
				'skip_signed_urls' => ! empty( $attr_options['link_skip_signed'] ) ? 1 : 0,
				'pass_token'       => ! empty( $attr_options['enable_cross_domain_token'] ) ? 1 : 0,
			),
			'forms'    => array(
				'client_fallback'         => ! empty( $attr_options['enable_js_injection'] ) ? 1 : 0,
				'watch_dynamic_content'   => ! empty( $attr_options['inject_mutation_observer'] ) ? 1 : 0,
				'replace_existing_values' => ! empty( $attr_options['inject_overwrite'] ) ? 1 : 0,
				'observer_target'         => (string) ( $attr_options['inject_observer_target'] ?? 'body' ),
				'webhook_sources_enabled' => ! empty( $feature_flags['external_webhooks'] ) ? 1 : 0,
				'whatsapp'                => array(
					'enabled'            => ! empty( $attr_options['enable_whatsapp'] ) ? 1 : 0,
					'append_attribution' => ! empty( $attr_options['whatsapp_append_attribution'] ) ? 1 : 0,
				),
				'providers'               => array(
					'calendly' => array(
						'enabled' => ! empty( $providers['calendly']['enabled'] ) ? 1 : 0,
						'secret'  => isset( $providers['calendly']['secret'] ) ? (string) $providers['calendly']['secret'] : '',
					),
					'hubspot'  => array(
						'enabled' => ! empty( $providers['hubspot']['enabled'] ) ? 1 : 0,
						'secret'  => isset( $providers['hubspot']['secret'] ) ? (string) $providers['hubspot']['secret'] : '',
					),
					'typeform' => array(
						'enabled' => ! empty( $providers['typeform']['enabled'] ) ? 1 : 0,
						'secret'  => isset( $providers['typeform']['secret'] ) ? (string) $providers['typeform']['secret'] : '',
					),
				),
			),
			'events'   => array(
				'browser_pipeline'              => ! empty( $feature_flags['event_v2'] ) ? 1 : 0,
				'woocommerce_storefront_events' => ! empty( $feature_flags['woocommerce_storefront_events'] ) ? 1 : 0,
				'gtm_container_id'              => isset( $gtm_settings['container_id'] ) ? (string) $gtm_settings['container_id'] : '',
				'destinations'                  => array(
					'meta'      => ! empty( $destinations['meta']['enabled'] ) ? 1 : 0,
					'google'    => ! empty( $destinations['google']['enabled'] ) ? 1 : 0,
					'linkedin'  => ! empty( $destinations['linkedin']['enabled'] ) ? 1 : 0,
					'reddit'    => ! empty( $destinations['reddit']['enabled'] ) ? 1 : 0,
					'pinterest' => ! empty( $destinations['pinterest']['enabled'] ) ? 1 : 0,
				),
				'lifecycle'        => array(
					'accept_updates'   => ! empty( $feature_flags['lifecycle_ingestion'] ) ? 1 : 0,
					'endpoint_enabled' => ! empty( $lifecycle['enabled'] ) ? 1 : 0,
					'token'            => isset( $lifecycle['token'] ) ? (string) $lifecycle['token'] : '',
				),
			),
			'delivery' => array(
				'server'     => array(
					'enabled'                  => ! empty( $server_effective['enabled'] ) ? 1 : 0,
					'endpoint_url'             => isset( $server_effective['endpoint_url'] ) ? (string) $server_effective['endpoint_url'] : '',
					'adapter'                  => isset( $server_effective['adapter'] ) ? (string) $server_effective['adapter'] : 'generic',
					'timeout'                  => absint( $server_effective['timeout'] ?? 5 ),
					'remote_failure_telemetry' => ! empty( $server_effective['remote_failure_telemetry'] ) ? 1 : 0,
					'use_network'              => $use_network ? 1 : 0,
					'has_network_defaults'     => $has_network ? 1 : 0,
				),
				'privacy'    => array(
					'enabled'        => ! empty( $consent_settings['enabled'] ) ? 1 : 0,
					'mode'           => isset( $consent_settings['mode'] ) ? (string) $consent_settings['mode'] : 'strict',
					'regions'        => $this->format_multiline_setting( $consent_settings['regions'] ?? array() ),
					'cmp_source'     => isset( $consent_settings['cmp_source'] ) ? (string) $consent_settings['cmp_source'] : 'auto',
					'cmp_timeout_ms' => absint( $consent_settings['cmp_timeout_ms'] ?? 3000 ),
				),
				'advanced'   => array(
					'use_native_adapters'      => ! empty( $feature_flags['connector_native'] ) ? 1 : 0,
					'store_event_diagnostics'  => ! empty( $feature_flags['diagnostics_v2'] ) ? 1 : 0,
					'encrypt_saved_secrets'    => ! empty( $security['encrypt_secrets_at_rest'] ) ? 1 : 0,
					'token_ttl_seconds'        => absint( $security['token_ttl_seconds'] ?? 0 ),
					'token_nonce_limit'        => absint( $security['token_nonce_limit'] ?? 0 ),
					'webhook_replay_window'    => absint( $security['webhook_replay_window'] ?? 0 ),
					'rate_limit_window'        => absint( $security['rate_limit_window'] ?? 0 ),
					'rate_limit_limit'         => absint( $security['rate_limit_limit'] ?? 0 ),
					'trusted_proxies'          => $this->format_multiline_setting( $security['trusted_proxies'] ?? array() ),
					'allowed_token_hosts'      => $this->format_multiline_setting( $security['allowed_token_hosts'] ?? array() ),
					'dispatch_buffer_size'     => absint( $diagnostics['dispatch_buffer_size'] ?? 20 ),
					'failure_flush_interval'   => absint( $diagnostics['failure_flush_interval'] ?? 10 ),
					'failure_bucket_retention' => absint( $diagnostics['failure_bucket_retention'] ?? 72 ),
					'dedup_ttl_seconds'        => absint( $dedup['ttl_seconds'] ?? 0 ),
				),
				'operations' => $this->get_delivery_operations_summary(),
			),
			'status'   => array(
				array(
					'key'   => 'capture',
					'label' => __( 'Capture', 'click-trail-handler' ),
					'value' => ! empty( $attr_options['enable_attribution'] ) ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' ),
					'tone'  => ! empty( $attr_options['enable_attribution'] ) ? 'success' : 'neutral',
				),
				array(
					'key'   => 'forms',
					'label' => __( 'Forms', 'click-trail-handler' ),
					'value' => $forms_active ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' ),
					'tone'  => $forms_active ? 'success' : 'neutral',
				),
				array(
					'key'   => 'events',
					'label' => __( 'Events', 'click-trail-handler' ),
					'value' => $events_active ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' ),
					'tone'  => $events_active ? 'success' : 'neutral',
				),
				array(
					'key'   => 'cross_domain',
					'label' => __( 'Cross-domain', 'click-trail-handler' ),
					'value' => $cross_domain_on ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' ),
					'tone'  => $cross_domain_on ? 'info' : 'neutral',
				),
				array(
					'key'   => 'delivery',
					'label' => __( 'Delivery', 'click-trail-handler' ),
					'value' => ! empty( $server_effective['enabled'] ) ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' ),
					'tone'  => ! empty( $server_effective['enabled'] ) ? 'success' : 'neutral',
				),
				array(
					'key'   => 'consent',
					'label' => __( 'Consent', 'click-trail-handler' ),
					'value' => ! empty( $consent_settings['enabled'] ) ? __( 'On', 'click-trail-handler' ) : __( 'Off', 'click-trail-handler' ),
					'tone'  => ! empty( $consent_settings['enabled'] ) ? 'success' : 'neutral',
				),
			),
			'urls'     => array(
				'logs'        => admin_url( 'admin.php?page=clicutcl-logs' ),
				'diagnostics' => admin_url( 'admin.php?page=clicutcl-diagnostics' ),
				'settings'    => admin_url( 'admin.php?page=clicutcl-settings' ),
			),
		);
	}

	/**
	 * Return a lightweight delivery operations snapshot for the Delivery tab.
	 *
	 * @return array<string, mixed>
	 */
	private function get_delivery_operations_summary() {
		$last_error = get_transient( 'clicutcl_last_error' );
		if ( ! is_array( $last_error ) ) {
			$last_error = get_option( 'clicutcl_last_error', array() );
		}

		$dispatches = get_transient( 'clicutcl_dispatch_buffer' );
		if ( ! is_array( $dispatches ) ) {
			$dispatches = get_option( 'clicutcl_dispatch_log', array() );
		}
		$dispatches = is_array( $dispatches ) ? array_values( $dispatches ) : array();

		if ( ! empty( $dispatches ) ) {
			usort(
				$dispatches,
				static function ( $left, $right ) {
					return (int) ( $right['time'] ?? 0 ) <=> (int) ( $left['time'] ?? 0 );
				}
			);
		}

		$latest_dispatch = ! empty( $dispatches ) ? $dispatches[0] : array();
		$latest_time     = isset( $latest_dispatch['time'] ) ? absint( $latest_dispatch['time'] ) : 0;
		$latest_http     = isset( $latest_dispatch['http_status'] ) ? absint( $latest_dispatch['http_status'] ) : 0;
		$queue_stats     = class_exists( 'CLICUTCL\\Server_Side\\Queue' ) ? Queue::get_stats() : array();
		$failure_buckets = Dispatcher::get_failure_telemetry();
		$failure_total   = 0;
		$debug_until     = get_transient( 'clicutcl_debug_until' );
		$debug_active    = $debug_until && (int) $debug_until > time();

		foreach ( $failure_buckets as $bucket ) {
			$failure_total += absint( $bucket['total'] ?? 0 );
		}

		return array(
			'queue_pending'        => absint( $queue_stats['pending'] ?? 0 ),
			'queue_due_now'        => absint( $queue_stats['due_now'] ?? 0 ),
			'last_error_code'      => isset( $last_error['code'] ) ? sanitize_key( (string) $last_error['code'] ) : '',
			'last_error_message'   => isset( $last_error['message'] ) ? sanitize_text_field( (string) $last_error['message'] ) : '',
			'last_error_time'      => isset( $last_error['time'] ) ? date_i18n( 'Y-m-d H:i:s', (int) $last_error['time'] ) : '',
			'latest_dispatch'      => $latest_http ? sprintf( 'HTTP %d', $latest_http ) : ( $latest_dispatch['status'] ?? __( 'No attempts yet', 'click-trail-handler' ) ),
			'latest_dispatch_time' => $latest_time
				? sprintf(
					/* translators: %s: relative time. */
					__( '%s ago', 'click-trail-handler' ),
					human_time_diff( $latest_time, time() )
				)
				: __( 'No delivery attempts recorded.', 'click-trail-handler' ),
			'failure_total'        => $failure_total,
			'debug_active'         => $debug_active ? 1 : 0,
			'debug_until'          => $debug_active ? date_i18n( 'Y-m-d H:i:s', (int) $debug_until ) : '',
		);
	}

	/**
	 * Normalize list-style settings into newline separated strings.
	 *
	 * @param mixed $value Raw list value.
	 * @return string
	 */
	private function format_multiline_setting( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_filter( array_map( 'trim', array_map( 'strval', $value ) ) ) );
		}

		$value = str_replace( ',', "\n", (string) $value );
		$lines = preg_split( '/[\r\n]+/', $value );
		$lines = is_array( $lines ) ? array_filter( array_map( 'trim', $lines ) ) : array();

		return implode( "\n", $lines );
	}

	/**
	 * Save the grouped admin settings payload back into the existing option stores.
	 *
	 * @param array $payload Settings payload.
	 * @return array<string, mixed>
	 */
	private function save_unified_admin_settings( $payload ) {
		$payload              = is_array( $payload ) ? wp_unslash( $payload ) : array();
		$capture              = isset( $payload['capture'] ) && is_array( $payload['capture'] ) ? $payload['capture'] : array();
		$forms                = isset( $payload['forms'] ) && is_array( $payload['forms'] ) ? $payload['forms'] : array();
		$events               = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array();
		$delivery             = isset( $payload['delivery'] ) && is_array( $payload['delivery'] ) ? $payload['delivery'] : array();
		$delivery_server      = isset( $delivery['server'] ) && is_array( $delivery['server'] ) ? $delivery['server'] : array();
		$delivery_privacy     = isset( $delivery['privacy'] ) && is_array( $delivery['privacy'] ) ? $delivery['privacy'] : array();
		$delivery_advanced    = isset( $delivery['advanced'] ) && is_array( $delivery['advanced'] ) ? $delivery['advanced'] : array();
		$forms_whatsapp       = isset( $forms['whatsapp'] ) && is_array( $forms['whatsapp'] ) ? $forms['whatsapp'] : array();
		$forms_providers      = isset( $forms['providers'] ) && is_array( $forms['providers'] ) ? $forms['providers'] : array();
		$events_destinations  = isset( $events['destinations'] ) && is_array( $events['destinations'] ) ? $events['destinations'] : array();
		$events_lifecycle     = isset( $events['lifecycle'] ) && is_array( $events['lifecycle'] ) ? $events['lifecycle'] : array();

		$attr_input = array(
			'enable_attribution'          => ! empty( $capture['enabled'] ) ? 1 : 0,
			'cookie_days'                 => $capture['retention_days'] ?? 90,
			'enable_link_decoration'      => ! empty( $capture['decorate_links'] ) ? 1 : 0,
			'link_allowed_domains'        => $capture['allowed_domains'] ?? '',
			'link_skip_signed'            => ! empty( $capture['skip_signed_urls'] ) ? 1 : 0,
			'enable_cross_domain_token'   => ! empty( $capture['pass_token'] ) ? 1 : 0,
			'enable_js_injection'         => ! empty( $forms['client_fallback'] ) ? 1 : 0,
			'inject_mutation_observer'    => ! empty( $forms['watch_dynamic_content'] ) ? 1 : 0,
			'inject_overwrite'            => ! empty( $forms['replace_existing_values'] ) ? 1 : 0,
			'inject_observer_target'      => $forms['observer_target'] ?? 'body',
			'enable_whatsapp'             => ! empty( $forms_whatsapp['enabled'] ) ? 1 : 0,
			'whatsapp_append_attribution' => ! empty( $forms_whatsapp['append_attribution'] ) ? 1 : 0,
		);
		update_option( 'clicutcl_attribution_settings', $this->sanitize_settings( $attr_input ), false );

		$consent_input = array(
			'enabled'        => ! empty( $delivery_privacy['enabled'] ) ? 1 : 0,
			'mode'           => $delivery_privacy['mode'] ?? 'strict',
			'regions'        => $delivery_privacy['regions'] ?? '',
			'cmp_source'     => $delivery_privacy['cmp_source'] ?? 'auto',
			'cmp_timeout_ms' => $delivery_privacy['cmp_timeout_ms'] ?? 3000,
		);
		update_option( Consent_Mode_Settings::OPTION, sanitize_option( Consent_Mode_Settings::OPTION, $consent_input ), false );

		$gtm_input = array(
			'container_id' => isset( $events['gtm_container_id'] ) ? (string) $events['gtm_container_id'] : '',
		);
		update_option( GTM_Settings::OPTION, sanitize_option( GTM_Settings::OPTION, $gtm_input ), false );

		$server_current = get_option( Settings::OPTION_SITE, array() );
		$server_current = is_array( $server_current ) ? $server_current : array();
		$server_network = Settings::get_network();
		$use_network    = is_multisite() && ! empty( $server_network ) && ! empty( $delivery_server['use_network'] );
		if ( $use_network ) {
			$server_input = $server_current;
			$server_input['use_network'] = 1;
		} else {
			$server_input = array(
				'enabled'                  => ! empty( $delivery_server['enabled'] ) ? 1 : 0,
				'endpoint_url'             => isset( $delivery_server['endpoint_url'] ) ? (string) $delivery_server['endpoint_url'] : '',
				'adapter'                  => isset( $delivery_server['adapter'] ) ? (string) $delivery_server['adapter'] : 'generic',
				'timeout'                  => $delivery_server['timeout'] ?? 5,
				'remote_failure_telemetry' => ! empty( $delivery_server['remote_failure_telemetry'] ) ? 1 : 0,
				'use_network'              => ! empty( $delivery_server['use_network'] ) ? 1 : 0,
			);
		}
		update_option( Settings::OPTION_SITE, $this->sanitize_server_side_settings( $server_input ), false );

		$tracking_input = array(
			'feature_flags'  => array(
				'event_v2'                     => ! empty( $events['browser_pipeline'] ) ? 1 : 0,
				'woocommerce_storefront_events' => ! empty( $events['woocommerce_storefront_events'] ) ? 1 : 0,
				'external_webhooks'            => ! empty( $forms['webhook_sources_enabled'] ) ? 1 : 0,
				'connector_native'             => ! empty( $delivery_advanced['use_native_adapters'] ) ? 1 : 0,
				'diagnostics_v2'               => ! empty( $delivery_advanced['store_event_diagnostics'] ) ? 1 : 0,
				'lifecycle_ingestion'          => ! empty( $events_lifecycle['accept_updates'] ) ? 1 : 0,
			),
			'destinations'   => array(
				'meta'      => array( 'enabled' => ! empty( $events_destinations['meta'] ) ? 1 : 0 ),
				'google'    => array( 'enabled' => ! empty( $events_destinations['google'] ) ? 1 : 0 ),
				'linkedin'  => array( 'enabled' => ! empty( $events_destinations['linkedin'] ) ? 1 : 0 ),
				'reddit'    => array( 'enabled' => ! empty( $events_destinations['reddit'] ) ? 1 : 0 ),
				'pinterest' => array( 'enabled' => ! empty( $events_destinations['pinterest'] ) ? 1 : 0 ),
			),
			'external_forms' => array(
				'providers' => array(
					'calendly' => array(
						'enabled' => ! empty( $forms_providers['calendly']['enabled'] ) ? 1 : 0,
						'secret'  => isset( $forms_providers['calendly']['secret'] ) ? (string) $forms_providers['calendly']['secret'] : '',
					),
					'hubspot'  => array(
						'enabled' => ! empty( $forms_providers['hubspot']['enabled'] ) ? 1 : 0,
						'secret'  => isset( $forms_providers['hubspot']['secret'] ) ? (string) $forms_providers['hubspot']['secret'] : '',
					),
					'typeform' => array(
						'enabled' => ! empty( $forms_providers['typeform']['enabled'] ) ? 1 : 0,
						'secret'  => isset( $forms_providers['typeform']['secret'] ) ? (string) $forms_providers['typeform']['secret'] : '',
					),
				),
			),
			'lifecycle'      => array(
				'crm_ingestion' => array(
					'enabled' => ! empty( $events_lifecycle['endpoint_enabled'] ) ? 1 : 0,
					'token'   => isset( $events_lifecycle['token'] ) ? (string) $events_lifecycle['token'] : '',
				),
			),
			'security'       => array(
				'token_ttl_seconds'       => $delivery_advanced['token_ttl_seconds'] ?? 0,
				'token_nonce_limit'       => $delivery_advanced['token_nonce_limit'] ?? 0,
				'webhook_replay_window'   => $delivery_advanced['webhook_replay_window'] ?? 0,
				'rate_limit_window'       => $delivery_advanced['rate_limit_window'] ?? 0,
				'rate_limit_limit'        => $delivery_advanced['rate_limit_limit'] ?? 0,
				'trusted_proxies'         => $delivery_advanced['trusted_proxies'] ?? '',
				'allowed_token_hosts'     => $delivery_advanced['allowed_token_hosts'] ?? '',
				'encrypt_secrets_at_rest' => ! empty( $delivery_advanced['encrypt_saved_secrets'] ) ? 1 : 0,
			),
			'diagnostics'    => array(
				'dispatch_buffer_size'     => $delivery_advanced['dispatch_buffer_size'] ?? 20,
				'failure_flush_interval'   => $delivery_advanced['failure_flush_interval'] ?? 10,
				'failure_bucket_retention' => $delivery_advanced['failure_bucket_retention'] ?? 72,
			),
			'dedup'          => array(
				'ttl_seconds' => $delivery_advanced['dedup_ttl_seconds'] ?? ( 7 * DAY_IN_SECONDS ),
			),
		);
		update_option( Tracking_Settings::OPTION, Tracking_Settings::sanitize( $tracking_input ), false );

		return $this->get_unified_admin_settings();
	}

	/**
	 * Render settings page with tabs.
	 */
	public function render_settings_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page navigation does not require nonce.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		$legacy_map = array(
			'general'    => 'attribution',
			'whatsapp'   => 'attribution',
			'channels'   => 'attribution',
			'gtm'        => 'destinations',
			'server'     => 'destinations',
			'trackingv2' => 'advanced',
		);
		if ( isset( $legacy_map[ $active_tab ] ) ) {
			$active_tab = $legacy_map[ $active_tab ];
		}

		$tabs = $this->get_settings_tabs();
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'attribution';
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

			<?php if ( 'attribution' === $active_tab ) : ?>
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

			<?php if ( 'advanced' === $active_tab ) : ?>
				<div class="clicktrail-inline-notice">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<span>
						<?php esc_html_e( "ClickTrail's core engine handles attribution capture. The sections below configure how captured events are packaged and delivered — each platform receives its own tailored payload, with consent signals already applied. You only need to configure the platforms you actively use. If you only run a single GTM container, you don't need anything here.", 'click-trail-handler' ); ?>
					</span>
				</div>
				<div id="clicutcl-tracking-v2-root"></div>

			<?php elseif ( 'destinations' === $active_tab ) : ?>
				<form action="options.php" method="post" class="clicktrail-settings-form">
					<?php
					settings_fields( 'clicutcl_gtm' );
					$this->render_settings_card(
						array(
							'id'          => 'gtm',
							'page'        => 'clicutcl_gtm',
							'section'     => 'clicutcl_gtm_section',
							'title'       => __( 'Google Tag Manager', 'click-trail-handler' ),
							'description' => __( 'Inject GTM on this site — only if GTM is not already loaded elsewhere.', 'click-trail-handler' ),
							'icon'        => 'dashicons-chart-bar',
						)
					);
					$this->render_settings_save_bar();
					?>
				</form>
				<form action="options.php" method="post" class="clicktrail-settings-form">
					<?php
					settings_fields( 'clicutcl_server_side' );
					$this->render_settings_card(
						array(
							'id'          => 'server',
							'page'        => 'clicutcl_server_side_tab',
							'section'     => 'clicutcl_server_side_section',
							'title'       => __( 'Server-side delivery', 'click-trail-handler' ),
							'description' => __( 'Route events through your own collector endpoint — bypasses browser ad-blockers and provides a reliable delivery path.', 'click-trail-handler' ),
							'icon'        => 'dashicons-cloud',
						)
					);
					$this->render_settings_save_bar();
					?>
				</form>
				<div class="clicktrail-inline-notice" style="margin-top:4px;">
					<span class="dashicons dashicons-share" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Advertising platform destinations below are managed through the v2 delivery pipeline. Each platform receives its own payload format independently.', 'click-trail-handler' ); ?></span>
				</div>
				<div id="clicutcl-destinations-v2-root"></div>

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
			'attribution'  => array(
				'label'       => __( 'Attribution', 'click-trail-handler' ),
				'title'       => __( 'Attribution', 'click-trail-handler' ),
				'description' => __( 'Capture UTM parameters, click IDs, and referrers — and keep them through forms, caching, and navigation.', 'click-trail-handler' ),
				'icon'        => 'dashicons-chart-area',
			),
			'consent'      => array(
				'label'       => __( 'Consent', 'click-trail-handler' ),
				'title'       => __( 'Consent', 'click-trail-handler' ),
				'description' => __( "Control when tracking is allowed to start, which regions require explicit opt-in, and which consent platform ClickTrail should listen to.", 'click-trail-handler' ),
				'icon'        => 'dashicons-privacy',
			),
			'destinations' => array(
				'label'       => __( 'Destinations', 'click-trail-handler' ),
				'title'       => __( 'Destinations', 'click-trail-handler' ),
				'description' => __( 'Configure where events are sent — your own server-side endpoint, GTM, and advertising platforms.', 'click-trail-handler' ),
				'icon'        => 'dashicons-share',
			),
			'advanced'     => array(
				'label'       => __( 'Advanced', 'click-trail-handler' ),
				'title'       => __( 'Advanced', 'click-trail-handler' ),
				'description' => __( 'Platform-level controls — feature flags, security, deduplication, lifecycle ingestion, and external form providers.', 'click-trail-handler' ),
				'icon'        => 'dashicons-admin-tools',
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
			case 'attribution':
				return array(
					'group' => 'clicutcl_attribution_settings',
				);
			case 'consent':
				return array(
					'group' => 'clicutcl_consent_mode',
				);
			default:
				return array(
					'group' => 'clicutcl_attribution_settings',
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
			case 'attribution':
				return array(
					array(
						'id'          => 'attr-core',
						'page'        => 'clicutcl_general_tab',
						'section'     => 'clicutcl_core_section',
						'title'       => __( 'Capture', 'click-trail-handler' ),
						'description' => __( 'Enable attribution tracking and set how long source data is stored.', 'click-trail-handler' ),
						'icon'        => 'dashicons-chart-area',
					),
					array(
						'id'          => 'attr-reliability',
						'page'        => 'clicutcl_general_tab',
						'section'     => 'clicutcl_reliability_section',
						'title'       => __( 'Cached pages', 'click-trail-handler' ),
						'description' => __( 'Ensures attribution still reaches hidden form fields when pages are served from cache. Recommended for most sites.', 'click-trail-handler' ),
						'icon'        => 'dashicons-shield-alt',
						'tag'         => __( 'Recommended', 'click-trail-handler' ),
						'tag_tone'    => 'recommended',
					),
					array(
						'id'          => 'attr-cross-domain',
						'page'        => 'clicutcl_general_tab',
						'section'     => 'clicutcl_cross_domain_section',
						'title'       => __( 'Cross-domain links', 'click-trail-handler' ),
						'description' => __( 'Preserve attribution when visitors follow links to your other domains or subdomains.', 'click-trail-handler' ),
						'icon'        => 'dashicons-admin-links',
					),
					array(
						'id'          => 'attr-whatsapp',
						'page'        => 'clicutcl_general_tab',
						'section'     => 'clicutcl_whatsapp_section',
						'title'       => __( 'WhatsApp', 'click-trail-handler' ),
						'description' => __( 'Carry attribution into outbound WhatsApp clicks and pre-filled messages.', 'click-trail-handler' ),
						'icon'        => 'dashicons-format-chat',
						'collapsible' => true,
						'collapsed'   => true,
					),
					array(
						'id'          => 'attr-advanced',
						'page'        => 'clicutcl_general_tab',
						'section'     => 'clicutcl_advanced_section',
						'title'       => __( 'Advanced', 'click-trail-handler' ),
						'description' => __( 'Low-level capture behavior. Most sites can leave these unchanged.', 'click-trail-handler' ),
						'icon'        => 'dashicons-admin-tools',
						'collapsible' => true,
						'collapsed'   => true,
						'tag'         => __( 'Advanced', 'click-trail-handler' ),
						'tag_tone'    => 'muted',
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
			default:
				return array();
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
