<?php
/**
 * Class ClickTrail_Admin
 *
 * @package   ClickTrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for managing admin settings.
 */
class ClickTrail_Admin {

	/**
	 * Context instance.
	 *
	 * @var ClickTrail\Core\Context
	 */
	protected $context;

	/**
	 * Constructor.
	 *
	 * @param ClickTrail\Core\Context $context Plugin context.
	 */
	public function __construct( $context ) {
		$this->context = $context;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'display_pii_warning' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'ClickTrail Settings', 'click-trail-handler' ),
			'ClickTrail',
			'manage_options',
			'clicktrail-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-chart-line',
			56 // Analytics plugin zone (after Plugins, near Yoast/MonsterInsights)
		);
	}

	/**
	 * Enqueue admin assets (conditional loading).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our plugin pages
		if ( strpos( $hook, 'clicktrail' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'clicktrail-admin',
			CLICKTRAIL_URL . 'assets/css/admin.css',
			array(),
			CLICKTRAIL_VERSION
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// 1. Attribution Settings (General & WhatsApp)
		register_setting( 'clicktrail_attribution_settings', 'clicktrail_attribution_settings', array( $this, 'sanitize_settings' ) );

		// General Section
		add_settings_section(
			'clicktrail_general_section',
			__( 'General Attribution Settings', 'click-trail-handler' ),
			null,
			'clicktrail_general_tab'
		);

		add_settings_field(
			'enable_attribution',
			__( 'Enable Attribution Tracking', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicktrail_general_tab',
			'clicktrail_general_section',
			array( 'label_for' => 'enable_attribution', 'option_name' => 'clicktrail_attribution_settings' )
		);

		add_settings_field(
			'cookie_days',
			__( 'Cookie Expiration (Days)', 'click-trail-handler' ),
			array( $this, 'render_number_field' ),
			'clicktrail_general_tab',
			'clicktrail_general_section',
			array( 'label_for' => 'cookie_days', 'option_name' => 'clicktrail_attribution_settings' )
		);

		// WhatsApp Section
		add_settings_section(
			'clicktrail_whatsapp_section',
			__( 'WhatsApp Tracking', 'click-trail-handler' ),
			null,
			'clicktrail_whatsapp_tab'
		);

		add_settings_field(
			'enable_whatsapp',
			__( 'Enable WhatsApp Tracking', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicktrail_whatsapp_tab',
			'clicktrail_whatsapp_section',
			array( 'label_for' => 'enable_whatsapp', 'option_name' => 'clicktrail_attribution_settings' )
		);

		add_settings_field(
			'whatsapp_append_attribution',
			__( 'Append Attribution to Message', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicktrail_whatsapp_tab',
			'clicktrail_whatsapp_section',
			array( 'label_for' => 'whatsapp_append_attribution', 'option_name' => 'clicktrail_attribution_settings' )
		);

		add_settings_field(
			'whatsapp_log_clicks',
			__( 'Log Clicks (Custom Post Type)', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicktrail_whatsapp_tab',
			'clicktrail_whatsapp_section',
			array( 'label_for' => 'whatsapp_log_clicks', 'option_name' => 'clicktrail_attribution_settings' )
		);

		// 2. Consent Mode Settings
		// Registered via Consent_Mode_Settings class, but we add section/fields here for display
		add_settings_section(
			'clicktrail_consent_section',
			__( 'Consent Mode Configuration', 'click-trail-handler' ),
			null,
			'clicktrail_consent_mode'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Consent Mode', 'click-trail-handler' ),
			array( $this, 'render_consent_checkbox' ),
			'clicktrail_consent_mode',
			'clicktrail_consent_section',
			array( 'label_for' => 'enabled' )
		);

		add_settings_field(
			'regions',
			__( 'Regions (e.g. EU)', 'click-trail-handler' ),
			array( $this, 'render_regions_field' ),
			'clicktrail_consent_mode',
			'clicktrail_consent_section',
			array( 'label_for' => 'regions' )
		);

		// 3. GTM Settings
		add_settings_section(
			'clicktrail_gtm_section',
			__( 'Google Tag Manager', 'click-trail-handler' ),
			null,
			'clicktrail_gtm'
		);

		add_settings_field(
			'container_id',
			__( 'Container ID (GTM-XXXXXX)', 'click-trail-handler' ),
			array( $this, 'render_gtm_text_field' ),
			'clicktrail_gtm',
			'clicktrail_gtm_section',
			array( 'label_for' => 'container_id' )
		);
	}

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
				<a href="?page=clicktrail-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-generic"></span>
					<?php esc_html_e( 'Attribution', 'click-trail-handler' ); ?>
				</a>
				<a href="?page=clicktrail-settings&tab=whatsapp" class="nav-tab <?php echo $active_tab == 'whatsapp' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-format-chat"></span>
					<?php esc_html_e( 'WhatsApp', 'click-trail-handler' ); ?>
				</a>
				<a href="?page=clicktrail-settings&tab=consent" class="nav-tab <?php echo $active_tab == 'consent' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-privacy"></span>
					<?php esc_html_e( 'Privacy & Consent', 'click-trail-handler' ); ?>
				</a>
				<a href="?page=clicktrail-settings&tab=gtm" class="nav-tab <?php echo $active_tab == 'gtm' ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-chart-bar"></span>
					<?php esc_html_e( 'Integrations', 'click-trail-handler' ); ?>
				</a>
			</h2>

			<form action="options.php" method="post">
				<?php
				if ( $active_tab == 'general' ) {
					settings_fields( 'clicktrail_attribution_settings' );
					do_settings_sections( 'clicktrail_general_tab' );
				} elseif ( $active_tab == 'whatsapp' ) {
					settings_fields( 'clicktrail_attribution_settings' );
					do_settings_sections( 'clicktrail_whatsapp_tab' );
				} elseif ( $active_tab == 'consent' ) {
					settings_fields( 'clicktrail_consent_mode' );
					do_settings_sections( 'clicktrail_consent_mode' );
				} elseif ( $active_tab == 'gtm' ) {
					settings_fields( 'clicktrail_gtm' );
					do_settings_sections( 'clicktrail_gtm' );
				}
				
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

    // ... Helper methods ...
    
    public function render_checkbox_field( $args ) {
		$option_name = $args['option_name'];
		$options = get_option( $option_name );
		$value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : 0;
		?>
		<input type="checkbox" name="<?php echo esc_attr( $option_name . '[' . $args['label_for'] . ']' ); ?>" value="1" <?php checked( 1, $value ); ?> />
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
        $settings = new ClickTrail\Modules\Consent_Mode\Consent_Mode_Settings();
        $value = $settings->get();
        $enabled = isset($value['enabled']) ? $value['enabled'] : 0;
        ?>
        <input type="checkbox" name="clicktrail_consent_mode[enabled]" value="1" <?php checked(1, $enabled); ?> />
        <?php
    }

    public function render_regions_field( $args ) {
        $settings = new ClickTrail\Modules\Consent_Mode\Consent_Mode_Settings();
        $value = $settings->get();
        $regions = isset($value['regions']) ? $value['regions'] : '';
        ?>
        <input type="text" name="clicktrail_consent_mode[regions]" value="<?php echo esc_attr($regions); ?>" class="regular-text" placeholder="EU, EE, UK" />
        <p class="description">Comma-separated list of region codes.</p>
        <?php
    }

    public function render_gtm_text_field( $args ) {
        $settings = new ClickTrail\Modules\GTM\GTM_Settings();
        $value = $settings->get();
        $id = isset($value['container_id']) ? $value['container_id'] : '';
        ?>
        <input type="text" name="clicktrail_gtm[container_id]" value="<?php echo esc_attr($id); ?>" class="regular-text" placeholder="GTM-XXXXXX" />
        <?php
    }

	public function ajax_log_pii_risk() {
		check_ajax_referer( 'clicktrail_pii_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions to log PII alerts.', 'click-trail-handler' ) ), 403 );
		}

		if ( isset( $_POST['pii_found'] ) && $_POST['pii_found'] === 'true' ) {
			update_option( 'clicktrail_pii_risk_detected', true );
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	public function display_pii_warning() {
		if ( get_option( 'clicktrail_pii_risk_detected' ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><strong><?php esc_html_e( 'ClickTrail Audit detected PII risk on your Thank You page. Your tracking may be deactivated by Google.', 'click-trail-handler' ); ?></strong></p>
				<p><a href="#" class="button button-primary"><?php esc_html_e( 'Fix PII Issues Now', 'click-trail-handler' ); ?></a></p>
			</div>
			<?php
		}
	}

	public function sanitize_settings( $input ) {
		$new_input = array();
		if( isset( $input['enable_attribution'] ) ) $new_input['enable_attribution'] = absint( $input['enable_attribution'] );
		if( isset( $input['cookie_days'] ) ) $new_input['cookie_days'] = absint( $input['cookie_days'] );
		if( isset( $input['enable_consent_banner'] ) ) $new_input['enable_consent_banner'] = absint( $input['enable_consent_banner'] );
		if( isset( $input['require_consent'] ) ) $new_input['require_consent'] = absint( $input['require_consent'] );
		if( isset( $input['consent_mode_region'] ) ) $new_input['consent_mode_region'] = sanitize_text_field( $input['consent_mode_region'] );
		if( isset( $input['enable_whatsapp'] ) ) $new_input['enable_whatsapp'] = absint( $input['enable_whatsapp'] );
		if( isset( $input['whatsapp_append_attribution'] ) ) $new_input['whatsapp_append_attribution'] = absint( $input['whatsapp_append_attribution'] );
		if( isset( $input['whatsapp_log_clicks'] ) ) $new_input['whatsapp_log_clicks'] = absint( $input['whatsapp_log_clicks'] );
		return $new_input;
	}

}
