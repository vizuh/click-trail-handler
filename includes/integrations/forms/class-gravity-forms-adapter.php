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
		// Dynamic population
		add_filter( 'gform_field_value', array( $this, 'populate_fields_dynamic' ), 10, 3 );
		
		// Submission persistence
		add_action( 'gform_after_submission', array( $this, 'on_submission' ), 10, 2 );
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
		$this->log_submission( 'gravityforms', $form['id'], $payload );
	}
}
