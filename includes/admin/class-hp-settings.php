<?php

class HP_Attribution_Admin {

	private $option_name = 'hp_attribution_settings';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_admin_menu() {
		add_options_page(
			'Attribution & Consent',
			'Attribution & Consent',
			'manage_options',
			'hp-attribution',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( $this->option_name, $this->option_name );

		add_settings_section(
			'hp_general_section',
			'General Settings',
			null,
			'hp-attribution'
		);

		add_settings_field(
			'enable_attribution',
			'Enable Attribution',
			array( $this, 'render_checkbox_field' ),
			'hp-attribution',
			'hp_general_section',
			array( 'label_for' => 'enable_attribution' )
		);

		add_settings_field(
			'cookie_days',
			'Cookie Duration (Days)',
			array( $this, 'render_number_field' ),
			'hp-attribution',
			'hp_general_section',
			array( 'label_for' => 'cookie_days', 'default' => 90 )
		);

		add_settings_section(
			'hp_consent_section',
			'Consent Settings',
			null,
			'hp-attribution'
		);

		add_settings_field(
			'enable_consent_banner',
			'Enable Consent Banner',
			array( $this, 'render_checkbox_field' ),
			'hp-attribution',
			'hp_consent_section',
			array( 'label_for' => 'enable_consent_banner' )
		);
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1>Attribution & Consent Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_name );
				do_settings_sections( 'hp-attribution' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_checkbox_field( $args ) {
		$options = get_option( $this->option_name );
		$value = isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : '';
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

}
