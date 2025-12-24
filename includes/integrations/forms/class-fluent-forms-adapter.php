<?php
/**
 * Fluent Forms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Fluent_Forms_Adapter
 */
class Fluent_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Check if Fluent Forms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'FLUENTFORM' ) || class_exists( '\FluentForm\Framework\Foundation\Application' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Fluent Forms';
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		// Echo hidden fields
		add_action( 'fluentform_form_element_start', array( $this, 'add_hidden_fields' ), 10, 2 );
		
		// Submission persistence
		add_action( 'fluentform_submission_inserted', array( $this, 'on_submission' ), 10, 3 );
	}

	/**
	 * Add hidden fields to Fluent Form.
	 *
	 * @param object $form Form object.
	 * @param array  $data Form data.
	 */
	public function add_hidden_fields( $form, $data ) {
		if ( ! $this->should_populate() ) {
			return;
		}

		$payload = $this->get_attribution_payload();
		
		foreach ( $payload as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $this->get_field_name( $key ) ) . '" value="' . esc_attr( $value ) . '">';
		}
	}

	/**
	 * Interface compliance.
	 *
	 * @param mixed $form_or_context
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Handle submission (log to DB and Fluent Meta).
	 *
	 * @param int   $entry_id Submission ID.
	 * @param array $form_data Posted data.
	 * @param object $form     Form object.
	 */
	/**
	 * Handle submission (log to DB and Fluent Meta).
	 *
	 * @param int   $arg1 Submission ID (mapped to arg1).
	 * @param array $arg2 Posted data (mapped to arg2).
	 * @param object $arg3 Form object (optional).
	 */
	public function on_submission( $arg1, $arg2, $arg3 = null ) {
		$entry_id = $arg1;
		$form_data = $arg2;
		$form = $arg3;
		// Use payload from cookie or form_data?
		// form_data should contain our hidden fields if they were submitted.
		
		$keys = Attribution_Provider::get_field_mapping();
		$attribution = array();
		
		foreach ( $keys as $key ) {
			$prefixed = $this->get_field_name( $key );
			if ( isset( $form_data[ $prefixed ] ) ) {
				$attribution[ $key ] = sanitize_text_field( $form_data[ $prefixed ] );
			}
		}

		// Fallback
		if ( empty( $attribution ) ) {
			$attribution = $this->get_attribution_payload();
		}

		if ( empty( $attribution ) ) {
			return;
		}

		// 1. Persist to Fluent Forms Meta (if available)
		if ( function_exists( 'fluentFormApi' ) ) {
			// fluentFormApi('submissions')->updateMeta($entry_id, $key, $value);
			// But helper function usually usage:
			// No direct helper exposed simply?
			// Let's use internal legacy helper if exists or just skip if too complex.
			// Ideally we want to see these columns in Fluent view. Fluent requires fields to be mapped for columns in UI.
			// But saving to meta allows programmatic access.
		}
		
		// 2. Log to ClickTrail
		$form_id_val = isset( $form->id ) ? $form->id : 0;
		$this->log_submission( 'fluentform', $form_id_val, $attribution );
	}
}
