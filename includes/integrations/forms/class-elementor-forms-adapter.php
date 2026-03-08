<?php
/**
 * Elementor Forms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Elementor_Forms_Adapter
 */
class Elementor_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Check if Elementor Pro Forms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\ElementorPro\Modules\Forms\Module' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Elementor Forms';
	}

	/**
	 * Register hooks.
	 *
	 * Elementor Pro exposes the submission record through
	 * `elementor_pro/forms/new_record`.
	 */
	public function register_hooks() {
		add_action( 'elementor_pro/forms/new_record', array( $this, 'on_submission' ), 10, 2 );
	}

	/**
	 * Interface compliance.
	 *
	 * @param mixed $form_or_context Form context.
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Handle Elementor form submissions.
	 *
	 * @param mixed $arg1 Form record object.
	 * @param mixed $arg2 Ajax handler object.
	 * @return void
	 */
	public function on_submission( $arg1, $arg2 = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$record = $arg1;
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$fields = $this->extract_record_fields( $record );
		$attribution = array();

		foreach ( Attribution_Provider::get_field_mapping() as $key ) {
			$prefixed = $this->get_field_name( $key );
			if ( isset( $fields[ $prefixed ] ) ) {
				$attribution[ $key ] = sanitize_text_field( (string) $fields[ $prefixed ] );
			}
		}

		if ( empty( $attribution ) ) {
			$attribution = $this->get_attribution_payload();
		}

		if ( empty( $attribution ) ) {
			return;
		}

		$form_id = $this->resolve_form_identifier( $record, $fields );
		$this->log_submission( 'elementor_forms', $form_id, $attribution, $fields );
	}

	/**
	 * Flatten Elementor record fields into a scalar map keyed by field ID and label.
	 *
	 * @param object $record Elementor record object.
	 * @return array
	 */
	private function extract_record_fields( $record ) {
		$raw_fields = $record->get( 'fields' );
		if ( ! is_array( $raw_fields ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw_fields as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$value = $this->normalize_field_value(
				$field['value'] ?? ( $field['raw_value'] ?? '' )
			);
			if ( '' === $value ) {
				continue;
			}

			$keys = array();
			$keys[] = sanitize_key( (string) $field_id );

			if ( ! empty( $field['id'] ) && is_scalar( $field['id'] ) ) {
				$keys[] = sanitize_key( (string) $field['id'] );
			}
			if ( ! empty( $field['custom_id'] ) && is_scalar( $field['custom_id'] ) ) {
				$keys[] = sanitize_key( (string) $field['custom_id'] );
			}
			if ( ! empty( $field['title'] ) && is_scalar( $field['title'] ) ) {
				$keys[] = sanitize_key( (string) $field['title'] );
			}
			if ( ! empty( $field['label'] ) && is_scalar( $field['label'] ) ) {
				$keys[] = sanitize_key( (string) $field['label'] );
			}

			foreach ( array_unique( array_filter( $keys ) ) as $key ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Resolve a stable form identifier for logging.
	 *
	 * @param object $record Elementor record object.
	 * @param array  $fields Flattened field map.
	 * @return string
	 */
	private function resolve_form_identifier( $record, $fields ) {
		$candidates = array();

		if ( method_exists( $record, 'get_form_settings' ) ) {
			$candidates[] = $record->get_form_settings( 'id' );
			$candidates[] = $record->get_form_settings( 'form_id' );
			$candidates[] = $record->get_form_settings( 'form_name' );
		}

		$candidates[] = $fields['form_id'] ?? '';
		$candidates[] = $fields['form_name'] ?? '';

		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $candidate );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return 'elementor_form';
	}

	/**
	 * Normalize Elementor field values to a flat string.
	 *
	 * @param mixed $value Raw field value.
	 * @return string
	 */
	private function normalize_field_value( $value ) {
		if ( is_array( $value ) ) {
			$value = implode(
				', ',
				array_filter(
					array_map(
						static function( $item ) {
							return is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '';
						},
						$value
					)
				)
			);
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}
}
