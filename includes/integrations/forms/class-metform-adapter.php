<?php
/**
 * MetForm Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MetForm_Adapter
 *
 * MetForm (Elementor form builder and its standalone block builder) submits every form through
 * one REST route into `MetForm\Core\Entries\Action::submit()`; it never fires an Elementor Pro
 * `elementor_pro/forms/*` hook, so `Elementor_Forms_Adapter` does not see a MetForm submission and
 * the two adapters cannot log the same one.
 *
 * Detection contract: a MetForm hidden input named `ct_<key>` for a `<key>` in
 * `Attribution_Provider::get_field_mapping()`. Elementor builder: Submit Button widget, Hidden Input
 * list, Input Name `ct_gclid`, empty Input Value. Block builder: Hidden Field block, Name `ct_gclid`,
 * Static value, empty. MetForm only keeps a posted key it declares, so a `ct_*` input that the form
 * does not carry is never stored and never added here.
 */
class MetForm_Adapter extends Abstract_Form_Adapter {

	/**
	 * Custom post type MetForm stores entries in.
	 */
	const ENTRY_POST_TYPE = 'metform-entry';

	/**
	 * Entry meta MetForm writes the form ID to, before `metform_after_store_form_data` fires.
	 */
	const ENTRY_FORM_META = 'metform_entries__form_id';

	/**
	 * Fill-marker decision per form ID for the current submit, recorded by `capture_request()`.
	 *
	 * @var array<string,bool>
	 */
	private $markers = array();

	/**
	 * Refreshed non-empty `ct_*` values per form ID for the current submit, recorded by `refresh_form_data()`.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $refreshed = array();

	/**
	 * Entry post ID MetForm last saved in this request; 0 when none (entries disabled for the form).
	 *
	 * @var int
	 */
	private $entry_id = 0;

	/**
	 * Check if MetForm is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'METFORM_VERSION' ) || class_exists( '\MetForm\Plugin' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'MetForm';
	}

	/**
	 * Register hooks.
	 *
	 * `Action::submit()` order: the entry post is inserted as a draft, `metform_before_store_form_data`
	 * (action, raw posted data), `metform_filter_before_store_form_data` (filter, the sanitised data
	 * MetForm stores and puts in its admin email), the entry is published with its data, then
	 * `metform_after_store_form_data` (action, raw posted data) and the notifications. The refresh
	 * runs on the filter (MetForm 3.4.0 and later; earlier versions never call it, so nothing is
	 * refreshed there but the ClickTrail log still runs); the log runs on the last action, with the
	 * entry ID taken from the entry post save. None of these names is deprecated.
	 */
	public function register_hooks() {
		add_action( 'metform_before_store_form_data', array( $this, 'capture_request' ), 10, 4 );
		add_filter( 'metform_filter_before_store_form_data', array( $this, 'refresh_form_data' ), 10, 4 );
		add_action( 'save_post_' . self::ENTRY_POST_TYPE, array( $this, 'remember_entry' ), 10, 1 );
		add_action( 'metform_after_store_form_data', array( $this, 'on_submission' ), 10, 4 );
	}

	/**
	 * Interface compliance.
	 *
	 * @param mixed $form_or_context Form context passed through unchanged.
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Record whether this submit carries the Injector's `ct_fill_source=js` marker.
	 *
	 * MetForm's sanitiser drops every key the form does not declare, so the marker is gone by the
	 * time `metform_filter_before_store_form_data` runs; this action still receives the raw posted
	 * data. The block builder posts `new FormData( form )`, which includes the marker the Injector
	 * appended. The Elementor builder posts its own state, never the DOM, so no marker reaches it.
	 *
	 * @param mixed $form_id       Form post ID.
	 * @param mixed $form_data     Raw posted data.
	 * @param mixed $form_settings Form settings (unused).
	 * @param mixed $attributes    Submission attributes (unused).
	 * @return void
	 */
	public function capture_request( $form_id, $form_data = array(), $form_settings = array(), $attributes = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$id = $this->form_key( $form_id );

		$this->markers[ $id ] = is_array( $form_data )
			&& isset( $form_data[ self::FILL_MARKER_FIELD ] )
			&& $this->has_fill_marker( $form_data[ self::FILL_MARKER_FIELD ] );
		unset( $this->refreshed[ $id ] );
	}

	/**
	 * Resolve the `ct_*` keys of the data MetForm is about to store (#171).
	 *
	 * Runs on `metform_filter_before_store_form_data`, so the entry, the admin email and every
	 * later integration hold the same value. Readable cookie: authoritative, keys it lacks are
	 * blanked. No cookie: posted values survive only with the `ct_fill_source=js` marker. Consent
	 * blocked: every `ct_*` blanked. Only `ct_<mapped key>` names the form posted are rewritten.
	 *
	 * @param mixed $form_data     Sanitised data about to be stored; null when no declared field was posted.
	 * @param mixed $form_id       Form post ID.
	 * @param mixed $form_settings Form settings (unused).
	 * @param mixed $attributes    Submission attributes (unused).
	 * @return mixed
	 */
	public function refresh_form_data( $form_data, $form_id = 0, $form_settings = array(), $attributes = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_array( $form_data ) ) {
			return $form_data;
		}

		$posted = array();
		foreach ( $form_data as $name => $value ) {
			$key = $this->mapped_key( $name );
			if ( null !== $key ) {
				$posted[ $key ] = $value;
			}
		}
		if ( empty( $posted ) ) {
			return $form_data;
		}

		$id       = $this->form_key( $form_id );
		$resolved = $this->resolve_submitted_attribution( $posted, ! empty( $this->markers[ $id ] ) );
		$kept     = array();
		foreach ( array_keys( $posted ) as $key ) {
			$form_data[ $this->get_field_name( $key ) ] = $resolved[ $key ];
			if ( '' !== $resolved[ $key ] ) {
				$kept[ $key ] = $resolved[ $key ];
			}
		}
		$this->refreshed[ $id ] = $kept;

		return $form_data;
	}

	/**
	 * Remember the entry post MetForm just saved. `Action::submit()` inserts it as a draft first and
	 * publishes it with the form data later, so the last save before the log is the entry.
	 *
	 * @param mixed $post_id Entry post ID.
	 * @return void
	 */
	public function remember_entry( $post_id ) {
		$this->entry_id = absint( $post_id );
	}

	/**
	 * Log the submission to ClickTrail and write the IDs (and attribution) to the MetForm entry.
	 *
	 * ClickTrail's own lead carries the full consented cookie payload whatever `ct_*` inputs the
	 * form has; the values refreshed above win where non-empty. Nothing is logged without
	 * submission-time consent.
	 *
	 * @param mixed $arg1 Form post ID.
	 * @param mixed $arg2 Raw posted data.
	 * @param mixed $arg3 Form settings (unused).
	 * @param mixed $arg4 Submission attributes (unused).
	 * @return void
	 */
	public function on_submission( $arg1, $arg2 = array(), $arg3 = null, $arg4 = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$id        = $this->form_key( $arg1 );
		$refreshed = isset( $this->refreshed[ $id ] ) ? $this->refreshed[ $id ] : array();
		$entry_id  = $this->entry_id_for( $arg1 );
		unset( $this->markers[ $id ], $this->refreshed[ $id ] );
		$this->entry_id = 0;

		if ( ! $this->refresh_allowed() ) {
			return;
		}

		$payload     = $this->get_attribution_payload();
		$attribution = is_array( $payload ) ? $payload : array();
		foreach ( $refreshed as $key => $value ) {
			$attribution[ $key ] = $value;
		}
		if ( empty( $attribution ) ) {
			return;
		}

		$form_id = is_numeric( $arg1 ) ? absint( $arg1 ) : sanitize_text_field( is_scalar( $arg1 ) ? (string) $arg1 : '' );
		$logged  = $this->log_submission( 'metform', $form_id, $attribution, is_array( $arg2 ) ? $arg2 : array(), $entry_id > 0 ? $entry_id : null );

		if ( $entry_id <= 0 || empty( $logged ) ) {
			return;
		}

		// A repeat of the same entry writes only the IDs it lacks (write-if-missing).
		$meta = $this->entry_meta_to_write(
			$logged,
			$attribution,
			static function ( $meta_key ) use ( $entry_id ) {
				return metadata_exists( 'post', $entry_id, $meta_key );
			}
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $entry_id, $this->get_field_name( $key ), wp_slash( sanitize_text_field( (string) $value ) ) );
		}
	}

	/**
	 * The entry post ID of this submit, or 0 when MetForm stored none for this form.
	 *
	 * @param mixed $form_id Form post ID.
	 * @return int
	 */
	private function entry_id_for( $form_id ) {
		if ( $this->entry_id <= 0 ) {
			return 0;
		}

		return absint( get_post_meta( $this->entry_id, self::ENTRY_FORM_META, true ) ) === absint( $form_id ) ? $this->entry_id : 0;
	}

	/**
	 * Array key for a form ID.
	 *
	 * @param mixed $form_id Form post ID.
	 * @return string
	 */
	private function form_key( $form_id ) {
		return is_scalar( $form_id ) ? (string) $form_id : '';
	}
}
