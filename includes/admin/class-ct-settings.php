<?php

class ClickTrail_Admin {

        private $option_name = 'clicktrail_attribution_settings';
        private $text_domain = 'clicktrail-consent-marketing-attribution';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'display_pii_warning' ) );
	}

	public function add_admin_menu() {
		add_menu_page(
			'ClickTrail Audit & Settings',
			'ClickTrail',
			'manage_options',
			'clicktrail',
			array( $this, 'render_settings_page' ),
			'dashicons-chart-area',
			30
		);
	}

	public function register_settings() {
		register_setting( $this->option_name, $this->option_name, array( $this, 'sanitize_settings' ) );

		// General Tab
		add_settings_section(
			'ct_general_section',
			'General Settings',
			null,
			'clicktrail_general'
		);

                add_settings_field(
                        'enable_attribution',
                        'Enable Attribution',
                        array( $this, 'render_checkbox_field' ),
                        'clicktrail_general',
                        'ct_general_section',
                        array( 'label_for' => 'enable_attribution', 'default' => 1 )
                );

		add_settings_field(
			'cookie_days',
			'Cookie Duration (Days)',
			array( $this, 'render_number_field' ),
			'clicktrail_general',
			'ct_general_section',
			array( 'label_for' => 'cookie_days', 'default' => 90 )
		);

		add_settings_section(
			'ct_consent_section',
			'Consent Settings',
			null,
			'clicktrail_general'
		);

                add_settings_field(
                        'enable_consent_banner',
                        'Enable Consent Banner',
                        array( $this, 'render_checkbox_field' ),
                        'clicktrail_general',
                        'ct_consent_section',
                        array( 'label_for' => 'enable_consent_banner', 'default' => 1 )
                );

                add_settings_field(
                        'require_consent',
                        'Require Consent for Tracking',
                        array( $this, 'render_checkbox_field' ),
                        'clicktrail_general',
                        'ct_consent_section',
                        array( 'label_for' => 'require_consent', 'default' => 1 )
                );

		add_settings_field(
			'consent_mode_region',
			'Consent Mode',
			array( $this, 'render_select_field' ),
			'clicktrail_general',
			'ct_consent_section',
			array(
				'label_for' => 'consent_mode_region',
				'default' => 'strict',
				'options' => array(
					'strict' => 'Strict (Default Denied)',
					'relaxed' => 'Relaxed (Default Granted)',
					'custom' => 'Custom (Geo-based)'
				)
			)
		);

		// WhatsApp Tab
		add_settings_section(
			'ct_whatsapp_section',
			'WhatsApp Tracking Settings',
			null,
			'clicktrail_whatsapp'
		);

		add_settings_field(
			'enable_whatsapp',
			'Enable WhatsApp Tracking',
			array( $this, 'render_checkbox_field' ),
			'clicktrail_whatsapp',
			'ct_whatsapp_section',
			array( 'label_for' => 'enable_whatsapp', 'default' => 1 )
		);

		add_settings_field(
			'whatsapp_append_attribution',
			'Append Attribution to Message',
			array( $this, 'render_checkbox_field' ),
			'clicktrail_whatsapp',
			'ct_whatsapp_section',
			array( 'label_for' => 'whatsapp_append_attribution', 'default' => 0 )
		);

		add_settings_field(
			'whatsapp_log_clicks',
			'Log WhatsApp Clicks',
			array( $this, 'render_checkbox_field' ),
			'clicktrail_whatsapp',
			'ct_whatsapp_section',
			array( 'label_for' => 'whatsapp_log_clicks', 'default' => 0 )
		);
	}

	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';
		?>
		<div class="wrap">
			<h1>ClickTrail Settings</h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=clicktrail&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">General</a>
				<a href="?page=clicktrail&tab=whatsapp" class="nav-tab <?php echo $active_tab === 'whatsapp' ? 'nav-tab-active' : ''; ?>">WhatsApp</a>
			</h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_name );
				if ( $active_tab === 'general' ) {
					do_settings_sections( 'clicktrail_general' );
				} else {
					do_settings_sections( 'clicktrail_whatsapp' );
				}
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

        public function render_checkbox_field( $args ) {
                $options = get_option( $this->option_name, array() );
                $default = isset( $args['default'] ) ? $args['default'] : '';
                $value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : $default;
                ?>
                <input type="checkbox" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( $this->option_name . '[' . $args['label_for'] . ']' ); ?>" value="1" <?php checked( 1, $value ); ?> />
                <?php
	}

	public function render_select_field( $args ) {
		$options = get_option( $this->option_name, array() );
		$default = isset( $args['default'] ) ? $args['default'] : '';
		$value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : $default;
		$select_options = isset( $args['options'] ) ? $args['options'] : array();
		?>
		<select id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( $this->option_name . '[' . $args['label_for'] . ']' ); ?>">
			<?php foreach ( $select_options as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_number_field( $args ) {
		$options = get_option( $this->option_name );
		$default = isset( $args['default'] ) ? $args['default'] : '';
		$value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : $default;
		?>
		<input type="number" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( $this->option_name . '[' . $args['label_for'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" />
		<?php
	}

	public function render_consent_section_description() {
		?>
		<p style="max-width: 800px; background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 10px 0;">
			<strong>⚠️ Consent Compliance Notice:</strong> The built-in consent banner is a basic solution suitable for small websites. For full GDPR/CCPA compliance or if you operate in highly regulated industries, we recommend using a dedicated Consent Management Platform (CMP) such as <a href="https://www.cookiebot.com/" target="_blank">Cookiebot</a>, <a href="https://www.onetrust.com/" target="_blank">OneTrust</a>, or <a href="https://borlabs.io/borlabs-cookie/" target="_blank">Borlabs Cookie</a>.
			<br><br>
			<em>Disclaimer: Ultimate compliance with privacy regulations is the responsibility of the website owner. This plugin provides tools to assist with consent management, but does not guarantee legal compliance.</em>
		</p>
		<?php
	}

	public function ajax_log_pii_risk() {
		check_ajax_referer( 'clicktrail_pii_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions to log PII alerts.', 'clicktrail-consent-marketing-attribution' ) ), 403 );
		}

                $pii_found = isset( $_POST['pii_found'] ) ? filter_var( wp_unslash( $_POST['pii_found'] ), FILTER_VALIDATE_BOOLEAN ) : false;

                if ( $pii_found ) {
                        update_option( 'ct_pii_risk_detected', true );
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	public function display_pii_warning() {
		if ( get_option( 'ct_pii_risk_detected' ) ) {
			?>
                        <div class="notice notice-error is-dismissible">
                                <p><strong><?php esc_html_e( 'ClickTrail Audit detected PII risk on your Thank You page. Your tracking may be deactivated by Google.', 'clicktrail-consent-marketing-attribution' ); ?></strong></p>
                                <p><a href="#" class="button button-primary"><?php esc_html_e( 'Fix PII Issues Now', 'clicktrail-consent-marketing-attribution' ); ?></a></p>
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
