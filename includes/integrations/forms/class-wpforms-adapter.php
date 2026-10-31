<?php
/**
 * WPForms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPForms_Adapter
 */
class WPForms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Check if WPForms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPForms' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'WPForms';
	}

	/**
	 * Register hooks.
	 *
	 * WPForms fires no per-field value filter for server-side population (its dynamic
	 * population reads `$_GET` only), so hidden fields are filled through
	 * `wpforms_field_properties` at render and refreshed through `wpforms_process_filter`
	 * at submit, before notifications and entries.
	 */
	public function register_hooks() {
		add_filter( 'wpforms_field_properties', array( $this, 'render_field_default' ), 10, 3 );
		add_filter( 'wpforms_process_filter', array( $this, 'refresh_fields' ), 10, 3 );

		// Submission processing.
		add_action( 'wpforms_process_complete', array( $this, 'on_submission' ), 10, 4 );
	}

	/**
	 * Resolve the ClickTrail field name a WPForms field is marked with.
	 *
	 * A field is a ClickTrail field when its CSS class list carries a `ct_<key>` token
	 * (recommended: WPForms fields have no name attribute) or its label is exactly `ct_<key>`
	 * for a key in `Attribution_Provider::get_field_mapping()` (the form the docs and the
	 * Diagnostics scan already accept; `ct_gclid note` is a label, not a marker).
	 *
	 * @param array $field Field settings from form data (`label`, `css`).
	 * @return string `ct_<key>`, or '' when the field is not marked.
	 */
	private function field_name( $field ) {
		if ( ! is_array( $field ) ) {
			return '';
		}
		if ( ! empty( $field['css'] ) && is_string( $field['css'] ) ) {
			foreach ( preg_split( '/\s+/', trim( $field['css'] ) ) as $token ) {
				if ( 0 === strpos( $token, $this->field_prefix ) ) {
					return $token;
				}
			}
		}
		$label = isset( $field['label'] ) && is_string( $field['label'] ) ? trim( $field['label'] ) : '';
		if ( 0 !== strpos( $label, $this->field_prefix ) ) {
			return '';
		}
		$key = substr( $label, strlen( $this->field_prefix ) );
		return in_array( $key, Attribution_Provider::get_field_mapping(), true ) ? $label : '';
	}

	/**
	 * Fill a marked hidden field from the attribution cookie at render.
	 *
	 * @param array $properties Field properties.
	 * @param array $field      Field settings.
	 * @param array $form_data  Form data (unused).
	 * @return array
	 */
	public function render_field_default( $properties, $field, $form_data = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $properties ) || ! is_array( $field ) || 'hidden' !== ( $field['type'] ?? '' ) ) {
			return $properties;
		}
		$name = $this->field_name( $field );
		if ( '' === $name || ! $this->refresh_allowed() ) {
			return $properties;
		}

		$value = $this->cookie_value_for( $name, $this->get_attribution_payload() );
		if ( null !== $value ) {
			$properties['inputs']['primary']['attr']['value'] = $value;
		}

		return $properties;
	}

	/**
	 * Refresh marked fields from the cookie sent with the submit.
	 *
	 * Runs on `wpforms_process_filter`, after validation and formatting and before
	 * notifications and the entry are written. Per key: a non-empty cookie key wins, keys the
	 * cookie lacks keep the posted value.
	 *
	 * @param array $fields    Processed fields keyed by field ID.
	 * @param array $entry     Raw entry (unused).
	 * @param array $form_data Form data.
	 * @return array
	 */
	public function refresh_fields( $fields, $entry, $form_data = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $fields ) || ! $this->refresh_allowed() ) {
			return $fields;
		}

		$payload = $this->get_attribution_payload();
		if ( empty( $payload ) ) {
			return $fields;
		}

		$settings = isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ? $form_data['fields'] : array();

		foreach ( $fields as $id => $field ) {
			if ( ! is_array( $field ) || ! array_key_exists( 'value', $field ) ) {
				continue;
			}
			// Processed fields carry the label as `name`; the CSS marker needs the form settings.
			$name = $this->field_name( $settings[ $id ] ?? array( 'label' => $field['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$value = $this->cookie_value_for( $name, $payload );
			if ( null !== $value ) {
				$fields[ $id ]['value'] = sanitize_text_field( $value );
			}
		}

		return $fields;
	}

	/**
	 * Interface compliance.
	 *
	 * @param mixed $form_or_context Form or context passed by the integration manager.
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Handle submission.
	 *
	 * @param array $fields    Sanitized field data.
	 * @param array $entry     Original entry $_POST.
	 * @param array $form_data Form data.
	 * @param int   $entry_id  Entry ID.
	 */
	/**
	 * Handle submission.
	 *
	 * @param mixed $fields    Sanitized field data (mapped to arg1).
	 * @param mixed $entry     Original entry $_POST (mapped to arg2).
	 * @param mixed $form_data Form data (optional extra).
	 * @param mixed $entry_id  Entry ID (optional extra).
	 */
	public function on_submission( $fields, $entry, $form_data = null, $entry_id = null ) {
		// Submission-time consent is authoritative for both provider and legacy modes.
		if ( ! $this->refresh_allowed() ) {
			return;
		}

		$payload = $this->get_attribution_payload();
		if ( empty( $payload ) ) {
			return;
		}

		$form_id  = isset( $form_data['id'] ) ? absint( $form_data['id'] ) : 0;
		$entry_id = absint( $entry_id );

		// Log first: the entry meta carries the IDs it returns, and a repeat of the same entry
		// (its meta was written by the first call) writes nothing.
		$logged = $this->log_submission( 'wpforms', $form_id, $payload, $this->extract_identity_from_fields( $fields ), $entry_id );
		if ( ! empty( $logged['duplicate'] ) ) {
			return;
		}

		// Save to WPForms entry meta only when a real entry exists.
		// Lite installs and some processing paths may not provide one.
		if (
			$entry_id > 0 &&
			$form_id > 0 &&
			function_exists( 'wpforms' ) &&
			isset( wpforms()->entry_meta ) &&
			is_object( wpforms()->entry_meta ) &&
			method_exists( wpforms()->entry_meta, 'add' )
		) {
			foreach ( array_merge( $payload, $this->entry_id_meta( $logged ) ) as $key => $value ) {
				$meta_key = $this->get_field_name( $key );
				wpforms()->entry_meta->add(
					array(
						'entry_id' => $entry_id,
						'form_id'  => $form_id,
						'user_id'  => get_current_user_id(),
						'type'     => $meta_key,
						'data'     => $value,
					)
				);
			}
		}
	}

	/**
	 * Extract email/phone candidates from sanitized WPForms field data.
	 *
	 * @param mixed $fields Sanitized field data.
	 * @return array
	 */
	private function extract_identity_from_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$identity = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$value = isset( $field['value'] ) && is_scalar( $field['value'] ) ? trim( (string) $field['value'] ) : '';
			if ( '' === $value ) {
				continue;
			}

			$type  = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';
			$label = '';
			if ( isset( $field['name'] ) && is_scalar( $field['name'] ) ) {
				$label = strtolower( (string) $field['name'] );
			} elseif ( isset( $field['label'] ) && is_scalar( $field['label'] ) ) {
				$label = strtolower( (string) $field['label'] );
			}

			if ( empty( $identity['email'] ) && ( 'email' === $type || false !== strpos( $label, 'email' ) ) && is_email( $value ) ) {
				$identity['email'] = sanitize_email( $value );
			}

			if ( empty( $identity['phone'] ) && ( 'phone' === $type || $this->is_phone_candidate( $label, $value ) ) ) {
				$identity['phone'] = sanitize_text_field( $value );
			}
		}

		return $identity;
	}
}
