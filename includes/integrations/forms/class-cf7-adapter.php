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
	 * Per-form scan results for this request, keyed by form ID.
	 *
	 * @var array<int|string, string[]>
	 */
	private static $referenced_keys = array();

	/**
	 * Add hidden fields to CF7 form.
	 *
	 * Only the mapped attribution keys the form's own markup references are emitted, as `ct_<key>`:
	 * a `ct_<key>` form-tag name in the form template (`[hidden ct_gclid]`) or a `[ct_<key>]` mail
	 * tag in the mail / mail_2 templates (#168). A form that references none gets none, so mail
	 * and add-ons that dump all fields never list empty `ct_*` lines. Values are empty unless the
	 * `clicutcl_render_server_attribution_values` filter opts in, so a cached page never carries
	 * one visitor's attribution to another (#152); the browser Injector fills the fields and the
	 * submit-time refresh corrects them.
	 *
	 * @param array $fields Hidden fields.
	 * @return array
	 */
	public function add_hidden_fields( $fields ) {
		$referenced = $this->referenced_keys( $this->current_form() );
		if ( empty( $referenced ) ) {
			return $fields;
		}

		$payload = $this->get_render_payload();
		foreach ( $referenced as $key ) {
			$fields[ $this->get_field_name( $key ) ] = isset( $payload[ $key ] ) ? $payload[ $key ] : '';
		}

		return $fields;
	}

	/**
	 * Form being rendered: CF7 sets it (`WPCF7_ContactForm::get_instance()`) before `form_html()`.
	 *
	 * @return object|null
	 */
	protected function current_form() {
		if ( class_exists( 'WPCF7_ContactForm' ) && is_callable( array( 'WPCF7_ContactForm', 'get_current' ) ) ) {
			$form = \WPCF7_ContactForm::get_current();
			return is_object( $form ) ? $form : null;
		}
		return null;
	}

	/**
	 * Mapped attribution keys a form's templates reference.
	 *
	 * @param object|null $form CF7 contact form.
	 * @return string[] Unprefixed keys.
	 */
	private function referenced_keys( $form ) {
		if ( ! $form || ! is_callable( array( $form, 'prop' ) ) ) {
			return array();
		}

		$id = is_callable( array( $form, 'id' ) ) ? $form->id() : spl_object_id( $form );
		if ( isset( self::$referenced_keys[ $id ] ) ) {
			return self::$referenced_keys[ $id ];
		}

		$names = array();
		// Form template: the name is the first token after the tag type, e.g. `[hidden ct_gclid]`.
		if ( preg_match_all( '/\[\s*[A-Za-z][\w*-]*\s+(ct_\w+)/', $this->flatten( $form->prop( 'form' ) ), $m ) ) {
			$names = $m[1];
		}
		// Mail templates: `[ct_gclid]` or `[_raw_ct_gclid]`.
		$mail = $this->flatten( $form->prop( 'mail' ) ) . "\n" . $this->flatten( $form->prop( 'mail_2' ) );
		if ( preg_match_all( '/\[\s*(?:_raw_)?(ct_\w+)\s*\]/', $mail, $m ) ) {
			$names = array_merge( $names, $m[1] );
		}

		$keys = array();
		foreach ( $names as $name ) {
			$key = $this->mapped_key( $name );
			if ( null !== $key ) {
				$keys[ $key ] = $key;
			}
		}

		self::$referenced_keys[ $id ] = array_values( $keys );
		return self::$referenced_keys[ $id ];
	}

	/**
	 * Join every string in a template property into one scannable string.
	 *
	 * @param mixed $value Template string or array of strings.
	 * @return string
	 */
	private function flatten( $value ) {
		if ( is_array( $value ) ) {
			return implode( "\n", array_map( array( $this, 'flatten' ), $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
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
			$key = $this->mapped_key( $name );
			if ( null !== $key ) {
				$posted[ $key ] = $value;
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

		// ClickTrail's own lead carries the full consented cookie payload whatever ct_* keys the form
		// references (#168); posted values, already refreshed from the cookie in refresh_posted_data(),
		// win only where non-empty. Nothing is logged without submission-time consent.
		if ( ! $this->refresh_allowed() ) {
			return;
		}

		$payload     = $this->get_attribution_payload();
		$attribution = is_array( $payload ) ? $payload : array();

		foreach ( Attribution_Provider::get_field_mapping() as $key ) {
			$prefixed = $this->get_field_name( $key );
			if ( ! isset( $posted_data[ $prefixed ] ) || ! is_scalar( $posted_data[ $prefixed ] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $posted_data[ $prefixed ] );
			if ( '' !== $value ) {
				$attribution[ $key ] = $value;
			}
		}

		// Posted ct_* values were refreshed from the submit-time cookie in refresh_posted_data()
		// (wpcf7_posted_data, priority 5), so CF7 mail, add-ons and this log share one source.
		$form_id = $contact_form->id();
		$this->log_submission( 'contact-form-7', $form_id, $attribution, is_array( $posted_data ) ? $posted_data : array() );
	}
}
