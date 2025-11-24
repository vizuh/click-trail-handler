<?php

class ClickTrail_Admin {

        private $option_name = 'ct_attribution_settings';
        private $text_domain = 'clicktrail';

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

		add_settings_section(
			'ct_general_section',
			'General Settings',
			null,
			'clicktrail'
		);

                add_settings_field(
                        'enable_attribution',
                        'Enable Attribution',
                        array( $this, 'render_checkbox_field' ),
                        'clicktrail',
                        'ct_general_section',
                        array( 'label_for' => 'enable_attribution', 'default' => 1 )
                );

		add_settings_field(
			'cookie_days',
			'Cookie Duration (Days)',
			array( $this, 'render_number_field' ),
			'clicktrail',
			'ct_general_section',
			array( 'label_for' => 'cookie_days', 'default' => 90 )
		);

		add_settings_section(
			'ct_consent_section',
			'Consent Settings',
			null,
			'clicktrail'
		);

                add_settings_field(
                        'enable_consent_banner',
                        'Enable Consent Banner',
                        array( $this, 'render_checkbox_field' ),
                        'clicktrail',
                        'ct_consent_section',
                        array( 'label_for' => 'enable_consent_banner', 'default' => 1 )
                );

                add_settings_field(
                        'require_consent',
                        'Require Consent for Tracking',
                        array( $this, 'render_checkbox_field' ),
                        'clicktrail',
                        'ct_consent_section',
                        array( 'label_for' => 'require_consent', 'default' => 1 )
                );
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1>Attribution & Consent Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_name );
				do_settings_sections( 'clicktrail' );
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

	public function render_number_field( $args ) {
		$options = get_option( $this->option_name );
		$default = isset( $args['default'] ) ? $args['default'] : '';
		$value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : $default;
		?>
		<input type="number" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( $this->option_name . '[' . $args['label_for'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" />
		<?php
	}

        public function ajax_log_pii_risk() {
                check_ajax_referer( 'clicktrail_pii_nonce', 'nonce' );

                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Insufficient permissions to log PII alerts.', $this->text_domain ) ), 403 );
                }

                if ( isset( $_POST['pii_found'] ) && $_POST['pii_found'] === 'true' ) {
                        update_option( 'hp_pii_risk_detected', true );
                        wp_send_json_success();
                }
		wp_send_json_error();
	}

	public function display_pii_warning() {
		if ( get_option( 'ct_pii_risk_detected' ) ) {
			?>
			<div class="notice notice-error is-dismissible">
                                <p><strong><?php _e( 'ClickTrail Audit detected PII risk on your Thank You page. Your tracking may be deactivated by Google.', $this->text_domain ); ?></strong></p>
                                <p><a href="#" class="button button-primary"><?php _e( 'Fix PII Issues Now', $this->text_domain ); ?></a></p>
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
		return $new_input;
	}

}
