<?php
/**
 * ClickTrail Admin Settings
 *
 * @package ClickTrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CLICUTCL_Admin
 */
class CLICUTCL_Admin {

	/**
	 * Context.
	 *
	 * @var string
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param string $context Context.
	 */
	public function __construct( $context ) {
		$this->context = $context;
	}

        /**
         * Initialize hooks.
         */
        public function init() {
                // Run early so the parent menu exists before CPT submenus attach.
                add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 1 );
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
			'clicutcl-settings',
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
		if ( strpos( $hook, 'clicutcl' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'clicutcl-admin',
			CLICUTCL_URL . 'assets/css/admin.css',
			array(),
			CLICUTCL_VERSION
		);
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

		add_settings_field(
			'whatsapp_log_clicks',
			__( 'Log Clicks (Custom Post Type)', 'click-trail-handler' ),
			array( $this, 'render_checkbox_field' ),
			'clicutcl_whatsapp_tab',
			'clicutcl_whatsapp_section',
			array( 
				'label_for' => 'whatsapp_log_clicks', 
				'option_name' => 'clicutcl_attribution_settings',
				'tooltip' => __( 'Save each WhatsApp click as a "WhatsApp Click" post in WordPress.', 'click-trail-handler' )
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
		?>
		<div class="clicktrail-toggle-wrapper">
			<label class="clicktrail-toggle">
				<input type="checkbox" name="<?php echo esc_attr( $option_name . '[' . $args['label_for'] . ']' ); ?>" value="1" <?php checked( 1, $value ); ?> />
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
		$settings = new CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings();
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
		$settings = new CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings();
		$value = $settings->get();
		$regions = isset($value['regions']) ? $value['regions'] : '';
		if ( is_array( $regions ) ) {
			$regions = implode( ', ', $regions );
		}
		?>
		<input type="text" name="clicutcl_consent_mode[regions]" value="<?php echo esc_attr($regions); ?>" class="regular-text" placeholder="EU, EE, UK" />
		<p class="description"><?php esc_html_e( 'Comma-separated list of region codes.', 'click-trail-handler' ); ?></p>
		<?php
	}

	public function render_gtm_text_field( $args ) {
		$settings = new CLICUTCL\Modules\GTM\GTM_Settings();
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
