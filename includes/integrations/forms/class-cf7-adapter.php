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
	public function on_submission( $contact_form, $abort = null, $submission = null ) {
		// If $submission is not passed (older CF7 versions), get instances.
		if ( ! $submission ) {
			$submission = \WPCF7_Submission::get_instance();
		}
		
		if ( ! $submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();
		
		// Extract attribution from posted data (since we added hidden fields)
		// Or get it fresh if verification needed? 
		// Relying on posted data is better as it reflects what was in the form at submission time.
		
		$attribution = array();
		
		// We iterate our known mapping to extract
		$keys = Attribution_Provider::get_field_mapping();
		foreach ( $keys as $key ) {
			$prefixed = $this->get_field_name( $key );
			if ( isset( $posted_data[ $prefixed ] ) ) {
				$attribution[ $key ] = sanitize_text_field( $posted_data[ $prefixed ] );
			}
		}
		
		if ( empty( $attribution ) ) {
			// Fallback: try to get from cookie if hidden fields failed?
			// But prompt says "Add submission persistence: Stored in CF7 database addons".
			// If we added hidden fields, they are in posted_data, and CF7 database plugins *typically* save all posted fields.
			// So by just adding hidden fields (done above), we satisfy "Stored in CF7 database addons".
			// But we also need to log to OUR table.
			
			// If empty, maybe try getting payload directly.
			$attribution = $this->get_attribution_payload();
		}

		$form_id = $contact_form->id();
		$this->log_submission( 'contact-form-7', $form_id, $attribution );
	}
}
