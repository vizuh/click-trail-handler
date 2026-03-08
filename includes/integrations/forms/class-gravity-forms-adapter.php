<?php
/**
 * Gravity Forms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gravity_Forms_Adapter
 */
class Gravity_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Check if Gravity Forms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'GFForms' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Gravity Forms';
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		// Register attribution keys as first-class Gravity Forms entry meta so they
		// appear in the entry detail view, list columns, exports, and merge tags.
		add_filter( 'gform_entry_meta', array( $this, 'register_entry_meta' ), 10, 2 );

		// Dynamic population
		add_filter( 'gform_field_value', array( $this, 'populate_fields_dynamic' ), 10, 3 );

		// Submission persistence
		add_action( 'gform_after_submission', array( $this, 'on_submission' ), 10, 2 );
	}

	/**
	 * Declare ClickTrail attribution keys as Gravity Forms entry meta.
	 *
	 * This makes the values visible in the entry detail screen, exportable via
	 * the Gravity Forms export tool, and searchable in the entries list.
	 *
	 * @param array $entry_meta Existing entry meta definitions.
	 * @param int   $form_id    Current form ID (unused; we register for all forms).
	 * @return array
	 */
	public function register_entry_meta( $entry_meta, $form_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$keys = \CLICUTCL\Core\Attribution_Provider::get_field_mapping();
		foreach ( $keys as $key ) {
			$meta_key              = $this->get_field_name( $key );
			$label                 = 'ClickTrail: ' . ucwords( str_replace( '_', ' ', $key ) );
			$entry_meta[ $meta_key ] = array(
				'label'             => $label,
				'is_numeric'        => false,
				'is_default_column' => false,
			);
		}
		return $entry_meta;
	}

	/**
	 * Populate fields dynamically.
	 *
	 * @param string $value The field value.
	 * @param object $field The field object.
	 * @param string $name  The parameter name.
	 * @return string Modified value.
	 */
	public function populate_fields_dynamic( $value, $field, $name ) {
		// Only handle our prefixed fields
		if ( 0 !== strpos( $name, $this->field_prefix ) ) {
			return $value;
		}

		if ( ! $this->should_populate() ) {
			return $value;
		}

		$payload = $this->get_attribution_payload();
		$key     = substr( $name, strlen( $this->field_prefix ) ); // Remove prefix

		return isset( $payload[ $key ] ) ? $payload[ $key ] : $value;
	}

	/**
	 * Placeholder for interface compliance. 
	 * Not used directly because GF uses specific filter signature.
	 *
	 * @param mixed $form_or_context
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Handle submission.
	 *
	 * @param array $entry The entry data.
	 * @param array $form  The form object.
	 */
	/**
	 * Handle submission.
	 *
	 * @param array $arg1 The entry data.
	 * @param array $arg2 The form object.
	 */
	public function on_submission( $arg1, $arg2 ) {
		$entry = $arg1;
		$form = $arg2;
		// Retrieve attribution from the entry actually submitted? 
		// Or retrieve from cookie at this moment?
		// Since fields were likely hidden fields populated by `populate_fields_dynamic`, 
		// the data should be in the entry object BUT usually hidden fields are part of $entry values.
		// However, to ensure we have the full picture even if fields weren't set up, 
		// we *could* grab from cookie again, but cleaner is to rely on what was submitted if mapped.
		
		// But the prompt says "Persist on submission ... Persist to entry meta".
		// If the user added hidden fields, they are in the entry.
		// Use gform_add_meta to add extra meta regardless of fields?
		
		$payload = $this->get_attribution_payload();
		if ( empty( $payload ) ) {
			return;
		}

		// 1. Save to Entry Meta (Gravity Forms specific storage)
		foreach ( $payload as $key => $value ) {
			$meta_key = $this->get_field_name( $key );
			// Check if already in entry to avoid duplication if hidden field exists?
			// gform_add_meta adds to `wp_gf_entry_meta` table usually or meta prop.
			\gform_add_meta( $entry['id'], $meta_key, $value );
		}

		// 2. Log to ClickTrail events
		$this->log_submission( 'gravityforms', $form['id'], $payload, $this->extract_identity_from_entry( $entry, $form ) );
	}

	/**
	 * Extract email/phone candidates from a Gravity entry using field metadata.
	 *
	 * @param array $entry Entry data.
	 * @param array $form  Form object.
	 * @return array
	 */
	private function extract_identity_from_entry( $entry, $form ) {
		if ( ! is_array( $entry ) || ! is_array( $form ) || empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return array();
		}

		$identity = array();

		foreach ( $form['fields'] as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}

			$field_id = isset( $field->id ) ? (string) $field->id : '';
			if ( '' === $field_id || ! isset( $entry[ $field_id ] ) || ! is_scalar( $entry[ $field_id ] ) ) {
				continue;
			}

			$value = trim( (string) $entry[ $field_id ] );
			if ( '' === $value ) {
				continue;
			}

			$type = '';
			if ( method_exists( $field, 'get_input_type' ) ) {
				$type = sanitize_key( (string) $field->get_input_type() );
			}
			if ( '' === $type && isset( $field->type ) ) {
				$type = sanitize_key( (string) $field->type );
			}

			$label = '';
			if ( isset( $field->adminLabel ) && is_scalar( $field->adminLabel ) ) {
				$label = strtolower( (string) $field->adminLabel );
			} elseif ( isset( $field->label ) && is_scalar( $field->label ) ) {
				$label = strtolower( (string) $field->label );
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
