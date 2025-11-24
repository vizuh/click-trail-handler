<?php

class ClickTrail_Form_Integrations {

	public function init() {
		// Contact Form 7
		add_filter( 'wpcf7_form_hidden_fields', array( $this, 'cf7_add_hidden_fields' ) );
		// add_action( 'wpcf7_before_send_mail', array( $this, 'cf7_save_attribution' ) ); // Optional if we want to save elsewhere

		// Gravity Forms
		add_filter( 'gform_pre_render', array( $this, 'gf_add_hidden_fields' ) );
		add_filter( 'gform_field_value', array( $this, 'gf_populate_fields' ), 10, 3 );
		// add_action( 'gform_after_submission', array( $this, 'gf_save_attribution' ), 10, 2 );

		// Fluent Forms
		add_action( 'fluentform_form_element_start', array( $this, 'ff_add_hidden_fields' ), 10, 2 );
		// add_action( 'fluentform_before_insert_submission', array( $this, 'ff_save_attribution' ), 10, 3 );
	}

	/**
	 * Contact Form 7: Add Hidden Fields
	 */
	public function cf7_add_hidden_fields( $fields ) {
		$attribution = ct_get_attribution();
		if ( ! $attribution ) {
			return $fields;
		}

		// Flatten the array for hidden fields
		$flat_data = $this->flatten_attribution( $attribution );

		foreach ( $flat_data as $key => $value ) {
			$fields[ 'ct_' . $key ] = $value;
		}

		return $fields;
	}

	/**
	 * Gravity Forms: Add Hidden Fields (Simplified)
	 * Note: GF is complex to add fields dynamically in pre_render without adding them to the form object.
	 * A better approach for GF is to use "Allow Field to be Populated Dynamically" in the UI, 
	 * BUT the requirement is "Automatic".
	 * 
	 * We can inject hidden inputs via a hook that outputs HTML inside the form tag, 
	 * but GF might strip them on submission if not registered.
	 * 
	 * Strategy: Use `gform_form_tag` to append hidden inputs? No, that's outside the form.
	 * Use `gform_after_open`?
	 */
	public function gf_add_hidden_fields( $form ) {
		// This is tricky in GF. Modifying the $form object to add fields on the fly is risky.
		// Alternative: Add a single hidden field that stores the JSON?
		// Or just rely on PHP submission hook to save meta.
		
		// For MVP, let's rely on saving meta during submission, 
		// and maybe inject a script to populate if fields exist.
		return $form;
	}

	public function gf_populate_fields( $value, $field, $name ) {
		// If user manually added fields with dynamic population parameter names
		// e.g. ct_utm_source
		if ( strpos( $name, 'ct_' ) === 0 ) {
			$key = substr( $name, 3 ); // remove ct_
			// Logic to find key in attribution array
			// ...
		}
		return $value;
	}

	/**
	 * Fluent Forms: Add Hidden Fields
	 */
	public function ff_add_hidden_fields( $form, $data ) {
		$attribution = ct_get_attribution();
		if ( ! $attribution ) {
			return;
		}
		$flat_data = $this->flatten_attribution( $attribution );
		foreach ( $flat_data as $key => $value ) {
			echo '<input type="hidden" name="ct_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
	}

	/**
	 * Helper to flatten attribution data
	 */
	private function flatten_attribution( $data ) {
		$flat = array();
		if ( isset( $data['first_touch'] ) ) {
			foreach ( $data['first_touch'] as $k => $v ) {
				$flat['ft_' . $k] = $v;
			}
		}
		if ( isset( $data['last_touch'] ) ) {
			foreach ( $data['last_touch'] as $k => $v ) {
				$flat['lt_' . $k] = $v;
			}
		}
		if ( isset( $data['landing_page'] ) ) $flat['landing_page'] = $data['landing_page']; // Wait, landing_page is inside touch?
		// My JS saves landing_page inside the touch object.
		
		return $flat;
	}

}
