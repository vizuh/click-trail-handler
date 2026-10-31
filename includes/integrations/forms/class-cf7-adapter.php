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

		// Priority 5 so mail tags, Flamingo and CRM add-ons filtering wpcf7_posted_data read refreshed values.
		add_filter( 'wpcf7_posted_data', array( $this, 'refresh_posted_data' ), 5 );

		// Record only CF7's successful outcome; pre-send can still abort or fail.
		add_action( 'wpcf7_mail_sent', array( $this, 'on_submission' ) );
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
	 * Refresh the posted ct_* data from the attribution cookie sent with the submit.
	 *
	 * `add_hidden_fields()` renders one input per cookie key from the page request, which on a
	 * landing visit runs before the browser writes the cookie (no inputs at all) and otherwise is
	 * one touch behind. The same rule as render applies at submit: every non-empty cookie key is
	 * written as `ct_<key>`; keys the cookie lacks keep the posted value. Runs inside
	 * WPCF7_Submission setup, so mail tags, Flamingo and `wpcf7_mail_sent` all see these values.
	 *
	 * @param array $posted_data Posted data (already unslashed by CF7).
	 * @return array
	 */
	public function refresh_posted_data( $posted_data ) {
		if ( ! is_array( $posted_data ) || ! $this->refresh_allowed() ) {
			return $posted_data;
		}

		foreach ( $this->get_attribution_payload() as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$posted_data[ $this->get_field_name( $key ) ] = sanitize_text_field( (string) $value );
		}

		return $posted_data;
	}

	/**
	 * Interface compliance.
	 *
	 * @param mixed $form_or_context Form or context passed through unchanged.
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
		if ( ! $this->should_populate() ) {
			return;
		}

		$contact_form = $arg1;
		$abort        = $arg2;
		$submission   = $arg3;
		// If $submission is not passed (older CF7 versions), get instances.
		if ( ! $submission ) {
			$submission = \WPCF7_Submission::get_instance();
		}

		if ( ! $submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();

		$attribution = array();

		// Check provider existence to prevent fatal if core is messed up.
		if ( class_exists( 'CLICUTCL\Core\Attribution_Provider' ) && is_callable( array( 'CLICUTCL\Core\Attribution_Provider', 'get_field_mapping' ) ) ) {
			// We iterate our known mapping to extract.
			$keys = Attribution_Provider::get_field_mapping();
			foreach ( $keys as $key ) {
				$prefixed = $this->get_field_name( $key );
				if ( isset( $posted_data[ $prefixed ] ) ) {
					$attribution[ $key ] = sanitize_text_field( $posted_data[ $prefixed ] );
				}
			}
		}

		// Posted ct_* values were refreshed from the submit-time cookie in refresh_posted_data()
		// (wpcf7_posted_data, priority 5), so CF7 mail, add-ons and this log share one source.
		$form_id = $contact_form->id();
		$this->log_submission( 'contact-form-7', $form_id, $attribution, is_array( $posted_data ) ? $posted_data : array() );
	}
}
