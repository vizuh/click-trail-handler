<?php
/**
 * SureForms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SureForms_Adapter
 *
 * SureForms (free) has no Hidden field block and posts through REST (`sureforms/v1/submit-form`),
 * not through the page. Its field keys are `srfm-<type>-<block id>-lbl-<base64 label>-<slug>`, so
 * the browser Injector's exact `name="ct_*"` match never finds one; the submit-time rule below is
 * the whole mechanism and needs no browser fill. The cookie is read from `$_COOKIE` on the REST
 * request, which a same-origin `fetch` carries.
 *
 * A field is a ClickTrail field when its decoded label is exactly `ct_<key>`, or its slug is
 * `ct-<key>` / `ct_<key>`, for a key in `Attribution_Provider::get_field_mapping()`. Any field
 * type works: a Text field hidden with CSS (free), or the Pro Hidden field.
 */
class SureForms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Resolved values of the `ct_*` fields the current submission carried, key (without the
	 * prefix) to value, recorded by `refresh_form_data()` for `on_submission()`.
	 *
	 * @var array<string,string>
	 */
	private $refreshed = array();

	/**
	 * Check if SureForms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'SRFM_VER' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'SureForms';
	}

	/**
	 * Register hooks.
	 *
	 * Both exist, with these signatures, from SureForms 1.12.0 to 2.13.0 and neither is deprecated:
	 * the filter `srfm_before_fields_processing( $form_data )` runs in `handle_form_entry()` after
	 * validation, unknown-key stripping and the unchecked-checkbox backfill and before the entry
	 * and the notification emails are built, so a refresh there reaches both; the action
	 * `srfm_form_submit( $response )` runs once the entry is stored and the emails are sent
	 * (`$response` carries `form_id`, `entry_id` and `data`, also on the "do not store entries"
	 * path, where it has no `entry_id`).
	 */
	public function register_hooks() {
		add_filter( 'srfm_before_fields_processing', array( $this, 'refresh_form_data' ), 10, 1 );
		add_action( 'srfm_form_submit', array( $this, 'on_submission' ), 10, 1 );
	}

	/**
	 * Interface compliance: SureForms renders no ClickTrail field, so the render rule (empty
	 * server-side values, #152) holds trivially.
	 *
	 * @param mixed $form_or_context Form or context passed through unchanged.
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Attribution key a SureForms field key is marked with, or null when it is not ClickTrail's.
	 *
	 * @param mixed $field_key Posted key, e.g. `srfm-input-1a2b3c4d-lbl-Y3RfZ2NsaWQ-ct-gclid`.
	 * @return string|null
	 */
	private function field_attribution_key( $field_key ) {
		$field_key = (string) $field_key;
		$offset    = strpos( $field_key, '-lbl-' );
		if ( false === $offset ) {
			return null;
		}

		// The base64 label has no `-`, so the first `-` after `-lbl-` starts the slug.
		$parts = explode( '-', substr( $field_key, $offset + 5 ), 2 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- SureForms stores the field label as unpadded base64 in the key; this is decoding, not obfuscation.
		$label = base64_decode( $parts[0] );
		$key   = false === $label ? null : $this->mapped_key( trim( $label ) );
		if ( null !== $key ) {
			return $key;
		}

		return isset( $parts[1] ) ? $this->mapped_key( str_replace( '-', '_', trim( $parts[1] ) ) ) : null;
	}

	/**
	 * Resolve the ct_* fields of the submission at submit time (#152).
	 *
	 * Runs on `srfm_before_fields_processing`, so the stored entry, the notification emails and
	 * ClickTrail's log hold the same value. Readable cookie: authoritative, keys it lacks are
	 * blanked. No cookie: the posted value survives only with the `ct_fill_source=js` marker.
	 * Consent blocked: every ct_* field blanked. The marker is stripped. Only fields marked
	 * `ct_<mapped key>` are rewritten, and none is ever added.
	 *
	 * @param array $form_data Submission data about to be processed.
	 * @return array
	 */
	public function refresh_form_data( $form_data ) {
		$this->refreshed = array();

		if ( ! is_array( $form_data ) ) {
			return $form_data;
		}

		$marker = isset( $form_data[ self::FILL_MARKER_FIELD ] ) && $this->has_fill_marker( $form_data[ self::FILL_MARKER_FIELD ] );
		unset( $form_data[ self::FILL_MARKER_FIELD ] );

		$posted = array();
		$names  = array();
		foreach ( $form_data as $name => $value ) {
			$key = $this->field_attribution_key( $name );
			if ( null === $key ) {
				continue;
			}
			$names[ $name ] = $key;
			$posted[ $key ] = $value;
		}
		if ( empty( $names ) ) {
			return $form_data;
		}

		$resolved = $this->resolve_submitted_attribution( $posted, $marker );
		foreach ( $names as $name => $key ) {
			$form_data[ $name ] = $resolved[ $key ];
			if ( '' !== $resolved[ $key ] ) {
				$this->refreshed[ $key ] = $resolved[ $key ];
			}
		}

		return $form_data;
	}

	/**
	 * Log the submission and write the attribution to the entry.
	 *
	 * ClickTrail's own record holds the consented cookie payload overlaid by the refreshed
	 * values (what the entry's ct_* fields hold), so a form with no ct_* field still gets the
	 * full record. The entry's `extras` column carries the attribution and the IDs.
	 *
	 * @param mixed $arg1 `$response` of `srfm_form_submit`: `form_id`, `entry_id` (absent when the
	 *                    form does not store entries) and `data` (submitted values by field slug).
	 * @param mixed $arg2 Unused.
	 * @return void
	 */
	public function on_submission( $arg1, $arg2 = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$refreshed       = $this->refreshed;
		$this->refreshed = array();

		if ( ! is_array( $arg1 ) || ! $this->refresh_allowed() ) {
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

		$form_id  = isset( $arg1['form_id'] ) && is_scalar( $arg1['form_id'] ) ? absint( $arg1['form_id'] ) : 0;
		$entry_id = isset( $arg1['entry_id'] ) && is_scalar( $arg1['entry_id'] ) ? absint( $arg1['entry_id'] ) : 0;
		$identity = isset( $arg1['data'] ) && is_array( $arg1['data'] ) ? $arg1['data'] : array();

		// Log first: the entry carries the IDs it returns. A repeat of the same entry writes only
		// the IDs the entry lacks (the first call wrote the rest, or died before it could).
		$logged = $this->log_submission( 'sureforms', $form_id, $attribution, $identity, $entry_id );
		if ( $entry_id <= 0 ) {
			return;
		}

		$extras = $this->entry_extras( $entry_id );
		if ( null === $extras ) {
			return;
		}

		$meta = $this->entry_meta_to_write(
			$logged,
			$attribution,
			static function ( $meta_key ) use ( $extras ) {
				return array_key_exists( $meta_key, $extras );
			}
		);

		$pairs = array();
		foreach ( $meta as $key => $value ) {
			$pairs[ $this->get_field_name( $key ) ] = (string) $value;
		}
		if ( empty( $pairs ) ) {
			return;
		}

		$this->save_entry_extras( $entry_id, array_merge( $extras, $pairs ) );
	}

	/**
	 * The entry's `extras` array, or null when it cannot be read as an array (no entries class,
	 * no such entry, a non-array value), so a write never replaces data it did not understand.
	 *
	 * @param int $entry_id SureForms entry ID.
	 * @return array|null
	 */
	protected function entry_extras( $entry_id ) {
		if ( ! class_exists( '\SRFM\Inc\Database\Tables\Entries' ) ) {
			return null;
		}

		$entry = \SRFM\Inc\Database\Tables\Entries::get( $entry_id );
		if ( ! is_array( $entry ) || empty( $entry ) ) {
			return null;
		}

		$extras = isset( $entry['extras'] ) ? $entry['extras'] : array();
		if ( '' === $extras || null === $extras ) {
			return array();
		}

		return is_array( $extras ) ? $extras : null;
	}

	/**
	 * Replace the entry's `extras` array.
	 *
	 * @param int   $entry_id SureForms entry ID.
	 * @param array $extras   Complete `extras` array.
	 * @return void
	 */
	protected function save_entry_extras( $entry_id, array $extras ) {
		\SRFM\Inc\Database\Tables\Entries::update( $entry_id, array( 'extras' => $extras ) );
	}
}
