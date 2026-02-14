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
use CLICUTCL\Server_Side\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 */
class Admin {

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
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// 1. Attribution Settings (General & WhatsApp)
		register_setting( 'clicutcl_attribution_settings', 'clicutcl_attribution_settings', array( $this, 'sanitize_settings' ) );

		// General Section
		add_settings_section(
			'clicutcl_general_section',
			__( 'General Attribution Settings', 'click-trail-handler' ),
			null,
			'clicutcl_general_tab'
		);

		add_settings_field(
			'enable_attribution',
			__( 'Enable Attribution Tracking', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_general_section',
			array( 
				'label_for' => 'enable_attribution', 
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip' => __( 'Automatically track UTM parameters and Click IDs from incoming traffic.', 'click-trail-handler' )
			)
		);

		add_settings_field(
			'cookie_days',
			__( 'Cookie Expiration (Days)', 'click-trail-handler' ),
			array( $this, 'render_number_field' ),
			'clicutcl_general_tab',
			'clicutcl_general_section',
			array( 'label_for' => 'cookie_days', 'option_name' => 'clicutcl_attribution_settings' )
		);

		// Advanced / Reliability Section
		add_settings_section(
			'clicutcl_advanced_section',
			__( 'Reliability & Cross-Domain', 'click-trail-handler' ),
			null,
			'clicutcl_general_tab'
		);

		add_settings_field(
			'enable_js_injection',
			__( 'Enable JS Field Injector', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_advanced_section',
			array( 
				'label_for' => 'enable_js_injection', 
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip' => __( 'CRITICAL: Fills hidden fields via JavaScript. Required for sites with caching (WP Rocket, WP Engine, Cloudflare).', 'click-trail-handler' ),
				'description' => __( 'Keep this ON to ensure data is captured even when the page is cached.', 'click-trail-handler' )
			)
		);

		add_settings_field(
			'inject_overwrite',
			__( 'Overwrite Existing Values', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_advanced_section',
			array( 
				'label_for' => 'inject_overwrite', 
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip' => __( 'If checked, JS will overwrite fields that already have a value.', 'click-trail-handler' )
			)
		);

		add_settings_field(
			'inject_mutation_observer',
			__( 'Use MutationObserver', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_advanced_section',
			array( 
				'label_for' => 'inject_mutation_observer', 
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip' => __( 'Detects forms in popups or loaded via AJAX (Elementor, etc).', 'click-trail-handler' )
			)
		);

		add_settings_field(
			'enable_link_decoration',
			__( 'Enable Link Decoration', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_advanced_section',
			array( 
				'label_for' => 'enable_link_decoration', 
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip' => __( 'Appends UTMs/Click IDs to outbound links.', 'click-trail-handler' )
			)
		);

		add_settings_field(
			'link_allowed_domains',
			__( 'Allowed Domains', 'click-trail-handler' ),
			array( $this, 'render_text_field' ),
			'clicutcl_general_tab',
			'clicutcl_advanced_section',
			array( 
				'label_for' => 'link_allowed_domains', 
				'option_name' => 'clicutcl_attribution_settings',
				'description' => __( 'Comma-separated list of domains to decorate (e.g., app.mysite.com, otherdomain.com).', 'click-trail-handler' )
			)
		);
		
		add_settings_field(
			'link_skip_signed',
			__( 'Skip Signed URLs', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_advanced_section',
			array( 
				'label_for' => 'link_skip_signed', 
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip' => __( 'Avoid decorating signed URLs (e.g. Amazon S3, secure tokens) to prevent breaking signatures.', 'click-trail-handler' )
			)
		);

		add_settings_field(
			'enable_cross_domain_token',
			__( 'Enable Cross-Domain Token', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_general_tab',
			'clicutcl_advanced_section',
			array(
				'label_for'   => 'enable_cross_domain_token',
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip'     => __( 'Attach a compact attribution token to outbound links for cross-domain continuity.', 'click-trail-handler' ),
				'description' => __( 'Adds a ct_token parameter to allowed cross-domain links. Token is limited to non-PII attribution fields.', 'click-trail-handler' ),
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
			__( 'Enable WhatsApp Tracking', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_whatsapp_tab',
			'clicutcl_whatsapp_section',
			array( 
				'label_for' => 'enable_whatsapp', 
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip' => __( 'Track clicks on WhatsApp links and buttons.', 'click-trail-handler' )
			)
		);

		add_settings_field(
			'whatsapp_append_attribution',
			__( 'Append Attribution to Message', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_whatsapp_tab',
			'clicutcl_whatsapp_section',
			array( 
				'label_for' => 'whatsapp_append_attribution', 
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip' => __( 'Add attribution data to the pre-filled WhatsApp message.', 'click-trail-handler' )
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
			__( 'Enable Consent Mode', 'click-trail-handler' ),
			array( $this, 'render_consent_checkbox' ),
			'clicutcl_consent_mode',
			'clicutcl_consent_section',
			array( 'label_for' => 'enabled' )
		);

		add_settings_field(
			'regions',
			__( 'Regions (e.g. EU)', 'click-trail-handler' ),
			array( $this, 'render_regions_field' ),
			'clicutcl_consent_mode',
			'clicutcl_consent_section',
			array( 'label_for' => 'regions' )
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
			__( 'Container ID (GTM-XXXXXX)', 'click-trail-handler' ),
			array( $this, 'render_gtm_text_field' ),
			'clicutcl_gtm',
			'clicutcl_gtm_section',
			array( 'label_for' => 'container_id' )
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
			__( 'Enable Server-side Sending', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_server_side_tab',
			'clicutcl_server_side_section',
			array(
				'label_for'   => 'enabled',
				'option_name' => 'clicutcl_server_side',
				'tooltip'     => __( 'Send canonical events to your self-hosted collector endpoint.', 'click-trail-handler' ),
			)
		);

		add_settings_field(
			'endpoint_url',
			__( 'Collector Endpoint URL', 'click-trail-handler' ),
			array( $this, 'render_text_field' ),
			'clicutcl_server_side_tab',
			'clicutcl_server_side_section',
			array(
				'label_for'   => 'endpoint_url',
				'option_name' => 'clicutcl_server_side',
				'description' => __( 'Example: https://sgtm.yourdomain.com/collect', 'click-trail-handler' ),
			)
		);

		add_settings_field(
			'adapter',
			__( 'Adapter Type', 'click-trail-handler' ),
			array( $this, 'render_select_field' ),
			'clicutcl_server_side_tab',
			'clicutcl_server_side_section',
			array(
				'label_for'   => 'adapter',
				'option_name' => 'clicutcl_server_side',
				'options'     => array(
					'generic'   => __( 'Generic Collector', 'click-trail-handler' ),
					'sgtm'      => __( 'sGTM (Server GTM)', 'click-trail-handler' ),
					'meta_capi' => __( 'Meta CAPI (future)', 'click-trail-handler' ),
				),
			)
		);

		add_settings_field(
			'timeout',
			__( 'Request Timeout (seconds)', 'click-trail-handler' ),
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
			__( 'Remote Failure Telemetry (Opt-in)', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_server_side_tab',
			'clicutcl_server_side_section',
			array(
				'label_for'   => 'remote_failure_telemetry',
				'option_name' => 'clicutcl_server_side',
				'tooltip'     => __( 'Off by default. When enabled, only aggregated failure counts are emitted via hook for external reporting.', 'click-trail-handler' ),
				'description' => __( 'No payloads or PII are included in remote failure telemetry.', 'click-trail-handler' ),
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
		?>
		<input type="text" name="<?php echo esc_attr( $option_name . '[' . $args['label_for'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
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
		?>
		<select name="<?php echo esc_attr( $option_name . '[' . $args['label_for'] . ']' ); ?>">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render settings page with tabs.

	/**
	 * Render settings page with tabs.
	 */
	public function render_settings_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin page navigation does not require nonce.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
		?>
		<div class="wrap clicktrail-settings-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<h2 class="nav-tab-wrapper">
				<a href="?page=clicutcl-settings&tab=general" class="nav-tab <?php echo esc_attr( 'general' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<span class="dashicons dashicons-admin-generic"></span>
					<?php esc_html_e( 'Attribution', 'click-trail-handler' ); ?>
				</a>
				<a href="?page=clicutcl-settings&tab=whatsapp" class="nav-tab <?php echo esc_attr( 'whatsapp' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<span class="dashicons dashicons-format-chat"></span>
					<?php esc_html_e( 'WhatsApp', 'click-trail-handler' ); ?>
				</a>
				<a href="?page=clicutcl-settings&tab=consent" class="nav-tab <?php echo esc_attr( 'consent' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<span class="dashicons dashicons-privacy"></span>
					<?php esc_html_e( 'Privacy & Consent', 'click-trail-handler' ); ?>
				</a>
				<a href="?page=clicutcl-settings&tab=gtm" class="nav-tab <?php echo esc_attr( 'gtm' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<span class="dashicons dashicons-chart-bar"></span>
					<?php esc_html_e( 'Integrations', 'click-trail-handler' ); ?>
				</a>
				<a href="?page=clicutcl-settings&tab=server" class="nav-tab <?php echo esc_attr( 'server' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<span class="dashicons dashicons-cloud"></span>
					<?php esc_html_e( 'Server-side', 'click-trail-handler' ); ?>
				</a>
			</h2>

			<form action="options.php" method="post">
				<?php
				if ( $active_tab == 'general' ) {
					settings_fields( 'clicutcl_attribution_settings' );
					do_settings_sections( 'clicutcl_general_tab' );
				} elseif ( $active_tab == 'whatsapp' ) {
					settings_fields( 'clicutcl_attribution_settings' );
					do_settings_sections( 'clicutcl_whatsapp_tab' );
				} elseif ( $active_tab == 'consent' ) {
					settings_fields( 'clicutcl_consent_mode' );
					do_settings_sections( 'clicutcl_consent_mode' );
				} elseif ( $active_tab == 'gtm' ) {
					settings_fields( 'clicutcl_gtm' );
					do_settings_sections( 'clicutcl_gtm' );
				} elseif ( $active_tab == 'server' ) {
					settings_fields( 'clicutcl_server_side' );
					do_settings_sections( 'clicutcl_server_side_tab' );
				}
				
				submit_button();
				?>
			</form>
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
			<span class="clicktrail-toggle-label">
				<?php echo $value ? esc_html__( 'Enabled', 'click-trail-handler' ) : esc_html__( 'Disabled', 'click-trail-handler' ); ?>
			</span>
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
		?>
		<input type="number" name="<?php echo esc_attr( $option_name . '[' . $args['label_for'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<?php
	}
	
	public function render_consent_checkbox( $args ) {
		$settings = new Consent_Mode_Settings();
		$value = $settings->get();
		$enabled = isset($value['enabled']) ? $value['enabled'] : 0;
		?>
		<div class="clicktrail-toggle-wrapper">
			<label class="clicktrail-toggle">
				<input type="checkbox" name="clicutcl_consent_mode[enabled]" value="1" <?php checked(1, $enabled); ?> />
				<span class="clicktrail-toggle-slider"></span>
			</label>
			<span class="clicktrail-toggle-label">
				<?php echo $enabled ? esc_html__( 'Enabled', 'click-trail-handler' ) : esc_html__( 'Disabled', 'click-trail-handler' ); ?>
			</span>
		</div>
		<?php
	}

	public function render_regions_field( $args ) {
		$settings = new Consent_Mode_Settings();
		$value = $settings->get();
		$regions = isset($value['regions']) ? $value['regions'] : '';
		if ( is_array( $regions ) ) {
			$regions = implode( ', ', $regions );
		}
		?>
		<input type="text" name="clicutcl_consent_mode[regions]" value="<?php echo esc_attr($regions); ?>" class="regular-text" placeholder="EEA, UK, US-CA" />
		<p class="description"><?php esc_html_e( 'Comma-separated region tokens (EEA, UK, US, US-CA, BR, DE...).', 'click-trail-handler' ); ?></p>
		<?php
	}

	public function render_gtm_text_field( $args ) {
		$settings = new GTM_Settings();
		$value = $settings->get();
		$id = isset($value['container_id']) ? $value['container_id'] : '';
		?>
		<input type="text" name="clicutcl_gtm[container_id]" value="<?php echo esc_attr($id); ?>" class="regular-text" placeholder="GTM-XXXXXX" />
		<?php
	}

	public function ajax_log_pii_risk() {
		check_ajax_referer( 'clicutcl_pii_nonce', 'nonce' );

		// Removed capability check - this is meant to be a public feature
		// Non-admin users can log PII risks detected on public pages
		
		// OPTIMIZATION: Check if already detected to save DB writes
		if ( get_option( 'clicutcl_pii_risk_detected' ) ) {
			wp_send_json_success();
		}

		$pii_found = isset( $_POST['pii_found'] ) ? filter_var( wp_unslash( $_POST['pii_found'] ), FILTER_VALIDATE_BOOLEAN ) : false;

		if ( $pii_found ) {
			update_option( 'clicutcl_pii_risk_detected', true );
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	public function display_pii_warning() {
		if ( get_option( 'clicutcl_pii_risk_detected' ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><strong><?php esc_html_e( 'ClickTrail Audit detected PII risk on your Thank You page. Your tracking may be deactivated by Google.', 'click-trail-handler' ); ?></strong></p>
				<p><a href="#" class="button button-primary"><?php esc_html_e( 'Fix PII Issues Now', 'click-trail-handler' ); ?></a></p>
			</div>
			<?php
		}
	}

	public function sanitize_settings( $input ) {
		$current = get_option( 'clicutcl_attribution_settings', array() );
		if ( ! current_user_can( 'manage_options' ) ) {
			return is_array( $current ) ? $current : array();
		}

		$input = is_array( $input ) ? wp_unslash( $input ) : array();

		$schema = $this->get_attribution_settings_schema();
		$current = is_array( $current ) ? $current : array();
		$merged = $this->apply_settings_defaults( $current, $schema );

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
	 * Ensure all known settings have stable defaults without dropping unknown keys.
	 *
	 * @param array $existing Existing stored settings.
	 * @param array $schema   Settings schema.
	 * @return array
	 */
	private function apply_settings_defaults( $existing, $schema ) {
		$existing = is_array( $existing ) ? $existing : array();
		foreach ( $schema as $key => $rule ) {
			if ( ! array_key_exists( $key, $existing ) ) {
				$existing[ $key ] = isset( $rule['default'] ) ? $rule['default'] : '';
			}
		}

		return $existing;
	}

	/**
	 * Sanitize checkbox/toggle values to 0|1.
	 *
	 * @param mixed $value Raw input.
	 * @return int
	 */
	private function sanitize_toggle( $value ) {
		return (int) (bool) $value;
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
			$allowed = array( 'generic', 'sgtm', 'meta_capi' );
			$new_input['adapter'] = in_array( $adapter, $allowed, true ) ? $adapter : 'generic';
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

	public function network_admin_menu() {
		add_menu_page(
			__( 'ClickTrail Network', 'click-trail-handler' ),
			__( 'ClickTrail', 'click-trail-handler' ),
			'manage_network_options',
			'clicutcl-network-settings',
			array( $this, 'render_network_settings_page' ),
			'dashicons-chart-area',
			56
		);
	}

	public function render_network_settings_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$options = Settings::get_network();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ClickTrail Network Settings', 'click-trail-handler' ); ?></h1>
			<form method="post" action="edit.php?action=clicutcl_network_settings">
				<?php wp_nonce_field( 'clicutcl_network_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Server-side Sending', 'click-trail-handler' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="clicutcl_server_side_network[enabled]" value="1" <?php checked( 1, $options['enabled'] ?? 0 ); ?> />
								<?php esc_html_e( 'Enabled', 'click-trail-handler' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Collector Endpoint URL', 'click-trail-handler' ); ?></th>
						<td>
							<input type="text" name="clicutcl_server_side_network[endpoint_url]" value="<?php echo esc_attr( $options['endpoint_url'] ?? '' ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Adapter Type', 'click-trail-handler' ); ?></th>
						<td>
							<select name="clicutcl_server_side_network[adapter]">
								<?php
								$adapter = $options['adapter'] ?? 'generic';
								$choices = array(
									'generic'   => __( 'Generic Collector', 'click-trail-handler' ),
									'sgtm'      => __( 'sGTM (Server GTM)', 'click-trail-handler' ),
									'meta_capi' => __( 'Meta CAPI (future)', 'click-trail-handler' ),
								);
								foreach ( $choices as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $adapter, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Request Timeout (seconds)', 'click-trail-handler' ); ?></th>
						<td>
							<input type="number" min="1" max="15" name="clicutcl_server_side_network[timeout]" value="<?php echo esc_attr( $options['timeout'] ?? 5 ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Remote Failure Telemetry (Opt-in)', 'click-trail-handler' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="clicutcl_server_side_network[remote_failure_telemetry]" value="1" <?php checked( 1, $options['remote_failure_telemetry'] ?? 0 ); ?> />
								<?php esc_html_e( 'Enable aggregated remote failure reporting hook', 'click-trail-handler' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Disabled by default. Sends only aggregated failure counts (no payloads, no PII).', 'click-trail-handler' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function save_network_settings() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'click-trail-handler' ) );
		}

		check_admin_referer( 'clicutcl_network_settings' );

		$raw = filter_input( INPUT_POST, 'clicutcl_server_side_network', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$raw = is_array( $raw ) ? $raw : array();
		$raw = map_deep( $raw, 'sanitize_text_field' );
		$clean = $this->sanitize_server_side_settings( $raw );

		update_site_option( Settings::OPTION_NETWORK, $clean );

		wp_safe_redirect( network_admin_url( 'admin.php?page=clicutcl-network-settings&updated=1' ) );
		exit;
	}

	public function diagnostics_page() {
		$last_error = get_transient( 'clicutcl_last_error' );
		if ( ! is_array( $last_error ) ) {
			$last_error = get_option( 'clicutcl_last_error', array() );
		}
		$last_error_time = '';
		if ( isset( $last_error['time'] ) ) {
			$last_error_time = date_i18n( 'Y-m-d H:i:s', (int) $last_error['time'] );
		}

		$dispatches = get_transient( 'clicutcl_dispatch_buffer' );
		if ( ! is_array( $dispatches ) ) {
			$dispatches = get_option( 'clicutcl_dispatch_log', array() );
		}
		if ( ! is_array( $dispatches ) ) {
			$dispatches = array();
		}
		$failure_telemetry = Dispatcher::get_failure_telemetry();

		$debug_until = get_transient( 'clicutcl_debug_until' );
		$debug_active = $debug_until && (int) $debug_until > time();
		$debug_until_str = $debug_active ? date_i18n( 'Y-m-d H:i:s', (int) $debug_until ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ClickTrail Diagnostics', 'click-trail-handler' ); ?></h1>

			<h2><?php esc_html_e( 'Endpoint Test', 'click-trail-handler' ); ?></h2>
			<p>
				<button class="button" id="clicutcl-test-endpoint"><?php esc_html_e( 'Test Endpoint', 'click-trail-handler' ); ?></button>
				<span id="clicutcl-test-endpoint-status" style="margin-left:10px;"></span>
			</p>
			<p class="description">
				<?php esc_html_e( 'Checks if your server-side endpoint is reachable.', 'click-trail-handler' ); ?>
			</p>

			<h2><?php esc_html_e( 'Last Error', 'click-trail-handler' ); ?></h2>
			<?php if ( ! empty( $last_error ) ) : ?>
				<p>
					<strong><?php echo esc_html( $last_error['code'] ?? '' ); ?></strong>
					<?php echo esc_html( $last_error['message'] ?? '' ); ?>
					<?php if ( $last_error_time ) : ?>
						<span style="margin-left:8px;color:#666;"><?php echo esc_html( $last_error_time ); ?></span>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'No errors recorded.', 'click-trail-handler' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Debug Logging', 'click-trail-handler' ); ?></h2>
			<p>
				<?php if ( $debug_active ) : ?>
					<strong><?php esc_html_e( 'Enabled', 'click-trail-handler' ); ?></strong>
					<span style="margin-left:8px;color:#666;"><?php echo esc_html( $debug_until_str ); ?></span>
				<?php else : ?>
					<strong><?php esc_html_e( 'Disabled', 'click-trail-handler' ); ?></strong>
				<?php endif; ?>
			</p>
			<p>
				<button class="button" id="clicutcl-debug-toggle" data-mode="<?php echo esc_attr( $debug_active ? 'off' : 'on' ); ?>">
					<?php echo esc_html( $debug_active ? __( 'Disable Debug', 'click-trail-handler' ) : __( 'Enable 15 Minutes', 'click-trail-handler' ) ); ?>
				</button>
				<span id="clicutcl-debug-status" style="margin-left:10px;"></span>
			</p>

			<h2><?php esc_html_e( 'Failure Telemetry (Always On)', 'click-trail-handler' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Failure-only aggregated counters. No payload bodies and no PII are stored.', 'click-trail-handler' ); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Hour Bucket', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Total Failures', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Codes', 'click-trail-handler' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $failure_telemetry ) ) : ?>
					<?php foreach ( $failure_telemetry as $bucket_key => $bucket ) : ?>
						<?php
						$bucket_start = isset( $bucket['bucket_start'] ) ? absint( $bucket['bucket_start'] ) : 0;
						if ( ! $bucket_start && preg_match( '/^\d{10}$/', (string) $bucket_key ) ) {
							$year        = (int) substr( (string) $bucket_key, 0, 4 );
							$month       = (int) substr( (string) $bucket_key, 4, 2 );
							$day         = (int) substr( (string) $bucket_key, 6, 2 );
							$hour        = (int) substr( (string) $bucket_key, 8, 2 );
							$bucket_start = gmmktime( $hour, 0, 0, $month, $day, $year );
						}
						$codes      = isset( $bucket['codes'] ) && is_array( $bucket['codes'] ) ? $bucket['codes'] : array();
						$code_parts = array();
						foreach ( $codes as $code => $count ) {
							$code_parts[] = sanitize_key( (string) $code ) . ': ' . absint( $count );
						}
						?>
						<tr>
							<td><?php echo esc_html( $bucket_start ? date_i18n( 'Y-m-d H:00', $bucket_start ) : (string) $bucket_key ); ?></td>
							<td><?php echo esc_html( (string) absint( $bucket['total'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( $code_parts ? implode( ' | ', $code_parts ) : '-' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="3"><?php esc_html_e( 'No failures recorded yet.', 'click-trail-handler' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent Dispatches', 'click-trail-handler' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Dispatch entries are captured only while Debug Logging is enabled.', 'click-trail-handler' ); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Event', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Event ID', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Adapter', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Status', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'HTTP', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'click-trail-handler' ); ?></th>
						<th><?php esc_html_e( 'Message', 'click-trail-handler' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $dispatches ) ) : ?>
					<?php foreach ( $dispatches as $dispatch ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', (int) ( $dispatch['time'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( $dispatch['event_name'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['event_id'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['adapter'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['status'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['http_status'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['endpoint_host'] ?? '' ); ?></td>
							<td><?php echo esc_html( $dispatch['message'] ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( 'No dispatches recorded yet.', 'click-trail-handler' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function ajax_test_endpoint() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'clicutcl_diag', 'nonce' );

		$result = Dispatcher::health_check();

		if ( $result->skipped ) {
			wp_send_json_error(
				array(
					'message' => __( 'Server-side adapter not configured.', 'click-trail-handler' ),
				)
			);
		}

		if ( $result->success ) {
			wp_send_json_success(
				array(
					// translators: %1$d: HTTP status code.
					'message' => sprintf( __( 'Endpoint reachable (HTTP %1$d).', 'click-trail-handler' ), (int) $result->status ),
				)
			);
		}

		wp_send_json_error(
			array(
				// translators: %1$d: HTTP status code, %2$s: error message.
				'message' => sprintf( __( 'Endpoint error (HTTP %1$d): %2$s', 'click-trail-handler' ), (int) $result->status, $result->message ),
			)
		);
	}

	public function ajax_toggle_debug() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'clicutcl_diag', 'nonce' );

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'on';
		if ( 'off' === $mode ) {
			delete_transient( 'clicutcl_debug_until' );
			wp_send_json_success( array( 'message' => __( 'Debug disabled.', 'click-trail-handler' ) ) );
		}

		$until = time() + ( 15 * MINUTE_IN_SECONDS );
		set_transient( 'clicutcl_debug_until', $until, 15 * MINUTE_IN_SECONDS );
		wp_send_json_success( array( 'message' => __( 'Debug enabled for 15 minutes.', 'click-trail-handler' ) ) );
	}

	public function logs_page() {
		require_once CLICUTCL_DIR . 'includes/admin/class-log-list-table.php';
		$table = new Log_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ClickTrail Logs', 'click-trail-handler' ); ?></h1>
			<form method="post">
				<?php
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

}
