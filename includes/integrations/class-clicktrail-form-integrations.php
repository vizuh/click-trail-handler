<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLICUTCL_Form_Integrations {

	public function init() {
		// Contact Form 7
		add_filter( 'wpcf7_form_hidden_fields', array( $this, 'cf7_add_hidden_fields' ) );
		// add_action( 'wpcf7_before_send_mail', array( $this, 'cf7_save_attribution' ) ); // Optional if we want to save elsewhere

		// Gravity Forms
		// add_filter( 'gform_pre_render', array( $this, 'gf_add_hidden_fields' ) );
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
                $attribution = clicutcl_get_attribution();
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



	public function gf_populate_fields( $value, $field, $name ) {
		// Check if the parameter name starts with 'ct_'
        if ( 0 === strpos( $name, 'ct_' ) ) {
            $key = substr( $name, 3 ); // remove 'ct_' prefix

            // Get attribution data
            $attribution = clicutcl_get_attribution();
            if ( ! $attribution ) {
                return $value;
            }

            // Flatten the data to find the key easily
            $flat_data = $this->flatten_attribution( $attribution );

            if ( isset( $flat_data[ $key ] ) ) {
                return $flat_data[ $key ];
            }
		}
		return $value;
	}

	/**
	 * Fluent Forms: Add Hidden Fields
	 */
	public function ff_add_hidden_fields( $form, $data ) {
                $attribution = clicutcl_get_attribution();
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
                if ( ! is_array( $data ) ) {
                        return array();
                }

                return $data;
        }

}
