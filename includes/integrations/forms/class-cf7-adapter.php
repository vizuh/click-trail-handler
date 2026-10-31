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
	 * Every key from `Attribution_Provider::get_field_mapping()` is emitted as `ct_<key>` on every
	 * render. Values are empty unless the `clicutcl_render_server_attribution_values` filter opts
	 * in, so a cached page never carries one visitor's attribution to another (#152); the browser
	 * Injector fills the fields and the submit-time refresh corrects them.
	 *
	 * @param array $fields Hidden fields.
	 * @return array
	 */
	public function add_hidden_fields( $fields ) {
		foreach ( $this->get_render_payload() as $key => $value ) {
			$fields[ $this->get_field_name( $key ) ] = $value;
		}

		return $fields;
	}

	/**
	 * Resolve the posted ct_* data at submit time (#152).
	 *
	 * The hidden inputs were rendered by a request that may belong to another visitor (page
	 * cache). A readable cookie is authoritative: its keys replace the posted values and keys it
	 * lacks are blanked. Without a cookie, posted values survive only when the Injector marked the
	 * form (`ct_fill_source=js`). Consent blocked blanks every `ct_*`. The marker is stripped. No
	 * key is added: only `ct_*` names the form posted are rewritten. Runs inside WPCF7_Submission
	 * setup, so mail tags, Flamingo and `wpcf7_mail_sent` all see these values.
	 *
	 * @param array $posted_data Posted data (already unslashed by CF7).
	 * @return array
	 */
	public function refresh_posted_data( $posted_data ) {
		if ( ! is_array( $posted_data ) ) {
			return $posted_data;
		}

		$marker = isset( $posted_data[ self::FILL_MARKER_FIELD ] ) && $this->has_fill_marker( $posted_data[ self::FILL_MARKER_FIELD ] );
		unset( $posted_data[ self::FILL_MARKER_FIELD ] );

		$posted = array();
		foreach ( $posted_data as $name => $value ) {
			if ( 0 === strpos( (string) $name, $this->field_prefix ) ) {
				$posted[ substr( (string) $name, strlen( $this->field_prefix ) ) ] = $value;
			}
		}
		if ( empty( $posted ) ) {
			return $posted_data;
		}

		$resolved = $this->resolve_submitted_attribution( $posted, $marker );
		foreach ( array_keys( $posted ) as $key ) {
			$posted_data[ $this->get_field_name( $key ) ] = $resolved[ $key ];
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
				if ( ! isset( $posted_data[ $prefixed ] ) ) {
					continue;
				}
				// Every mapped key is rendered; only the ones that hold a value are attribution.
				$value = sanitize_text_field( $posted_data[ $prefixed ] );
				if ( '' !== $value ) {
					$attribution[ $key ] = $value;
				}
			}
		}

		// Posted ct_* values were refreshed from the submit-time cookie in refresh_posted_data()
		// (wpcf7_posted_data, priority 5), so CF7 mail, add-ons and this log share one source.
		$form_id = $contact_form->id();
		$this->log_submission( 'contact-form-7', $form_id, $attribution, is_array( $posted_data ) ? $posted_data : array() );
	}
}
