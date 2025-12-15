<?php
/**
 * Form Integrations
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations;

use CLICUTCL\Utils\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Integrations
 *
 * @deprecated 1.3.0 Use Form_Integration_Manager and specific adapters instead.
 */
class Form_Integrations {

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Contact Form 7
		add_filter( 'wpcf7_form_hidden_fields', array( $this, 'cf7_add_hidden_fields' ) );

		// Gravity Forms
		add_filter( 'gform_field_value', array( $this, 'gf_populate_fields' ), 10, 3 );

		// Fluent Forms
		add_action( 'fluentform_form_element_start', array( $this, 'ff_add_hidden_fields' ), 10, 2 );
	}

	/**
	 * Contact Form 7: Add Hidden Fields
	 *
	 * @param array $fields Hidden fields.
	 * @return array
	 */
	public function cf7_add_hidden_fields( $fields ) {
		$attribution = Attribution::get();
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
	 * Gravity Forms: Populate Fields
	 *
	 * @param string $value Field value.
	 * @param object $field Field object.
	 * @param string $name  Parameter name.
	 * @return string
	 */
	public function gf_populate_fields( $value, $field, $name ) {
		// Check if the parameter name starts with 'ct_'
		if ( 0 === strpos( $name, 'ct_' ) ) {
			$key = substr( $name, 3 ); // remove 'ct_' prefix

			// Get attribution data
			$attribution = Attribution::get();
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
	 *
	 * @param object $form Form object.
	 * @param array  $data Form data.
	 */
	public function ff_add_hidden_fields( $form, $data ) {
		$attribution = Attribution::get();
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
	 *
	 * @param array $data Attribution data.
	 * @return array
	 */
	private function flatten_attribution( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		return $data;
	}
}
