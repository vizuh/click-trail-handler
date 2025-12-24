<?php
/**
 * Contact Form 7 Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CF7_Adapter
 */
class CF7_Adapter extends Abstract_Form_Adapter {

	/**
	 * Check if CF7 is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPCF7' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Contact Form 7';
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_filter( 'wpcf7_form_hidden_fields', array( $this, 'add_hidden_fields' ) );
		
		// Log submission
		add_action( 'wpcf7_before_send_mail', array( $this, 'on_submission' ), 10, 3 );
	}

	/**
	 * Add hidden fields to CF7 form.
	 *
	 * @param array $fields Hidden fields.
	 * @return array
	 */
	public function add_hidden_fields( $fields ) {
		if ( ! $this->should_populate() ) {
			return $fields;
		}

		$payload = $this->get_attribution_payload();
		
		foreach ( $payload as $key => $value ) {
			$fields[ $this->get_field_name( $key ) ] = $value;
		}

		return $fields;
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
	 * Handle submission (log to DB).
	 *
	 * @param object $contact_form CF7 Form object.
	 * @param bool   $abort        Abort status.
	 * @param object $submission   Submission object.
	 */
	/**
	 * Handle submission (log to DB).
	 *
	 * @param object $arg1 CF7 Form object.
	 * @param bool   $arg2 Abort status.
	 * @param object $arg3 Submission object.
	 */
	public function on_submission( $arg1, $arg2 = null, $arg3 = null ) {
		$contact_form = $arg1;
		$abort = $arg2;
		$submission = $arg3;
		// If $submission is not passed (older CF7 versions), get instances.
		if ( ! $submission ) {
			$submission = \WPCF7_Submission::get_instance();
		}
		
		if ( ! $submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();
		
		$attribution = array();
		
		// Check provider existence to prevent fatal if core is messed up
		if ( class_exists( 'CLICUTCL\Core\Attribution_Provider' ) && is_callable( array( 'CLICUTCL\Core\Attribution_Provider', 'get_field_mapping' ) ) ) {
			// We iterate our known mapping to extract
			$keys = Attribution_Provider::get_field_mapping();
			foreach ( $keys as $key ) {
				$prefixed = $this->get_field_name( $key );
				if ( isset( $posted_data[ $prefixed ] ) ) {
					$attribution[ $key ] = sanitize_text_field( $posted_data[ $prefixed ] );
				}
			}
		}
		
		if ( empty( $attribution ) ) {
			// If empty, maybe try getting payload directly.
			$attribution = $this->get_attribution_payload();
		}

		$form_id = $contact_form->id();
		$this->log_submission( 'contact-form-7', $form_id, $attribution );
	}
}
