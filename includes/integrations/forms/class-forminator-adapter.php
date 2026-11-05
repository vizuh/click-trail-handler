<?php
/**
 * Forminator Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Forminator_Adapter
 *
 * Forminator names a hidden input after the field ID (`hidden-1`), so the browser runtime's
 * `name="ct_*"` match never reaches it. The adapter works on the server instead: a Hidden field the
 * form builder marks as ClickTrail's is rendered empty and filled from the submit-time cookie before
 * Forminator validates, stores and mails the submission. Forminator submits through admin-ajax
 * (`forminator_submit_form_custom-forms`) by default; the same code path handles non-AJAX forms.
 */
class Forminator_Adapter extends Abstract_Form_Adapter {

	/**
	 * Hidden-field default values Forminator reapplies to the stored value after
	 * `forminator_custom_form_submit_field_data` (`handle_hidden_fields_after_entry_save()`).
	 *
	 * @var string[]
	 */
	private const SERVER_OWNED_DEFAULTS = array( 'custom_value', 'embed_id', 'embed_title', 'embed_url' );

	/**
	 * Entry statuses that are not a submission: abandoned-form pings, saved drafts, spam.
	 *
	 * @var string[]
	 */
	private const NON_SUBMISSION_STATUSES = array( 'draft', 'abandoned', 'spam' );

	/**
	 * Entry meta waiting for Forminator to finish storing the entry, by entry ID.
	 *
	 * @var array<int,array{entry:object,logged:mixed,payload:array}>
	 */
	private $deferred = array();

	/**
	 * Check if Forminator is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'FORMINATOR_VERSION' ) || class_exists( 'Forminator' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Forminator';
	}

	/**
	 * Register hooks.
	 *
	 * Verified against Forminator 1.58.4 (`library/fields/hidden.php`,
	 * `library/modules/custom-forms/front/front-action.php`); none of these is a deprecated alias.
	 * `forminator_prepared_data` is present in 1.17.1 and absent in 1.15.0.1; the other three are present
	 * in 1.14.12. Before 1.17 a marked field therefore stays empty (render filter) and is never refreshed.
	 *
	 * - `forminator_field_hidden_field_value` runs when a Hidden field computes its value, at render
	 *   and at submit: a marked field is empty unless the render filter opts in (#152).
	 * - `forminator_prepared_data` runs on the posted data after Forminator handled the Hidden fields and
	 *   before it builds the entry fields, so the cookie value is the one Forminator validates, stores and
	 *   mails. `forminator_custom_form_submit_field_data` runs after that and only keeps Forminator from
	 *   overwriting the value (see `protect_entry_fields()`).
	 * - `forminator_custom_form_submit_before_set_fields` runs once the entry exists: it logs and writes the entry meta.
	 * - `forminator_after_handle_form` (`do_action( ..., $entry )` at the end of `handle_form()`, after the entry fields,
	 *   add-ons and mail) writes the entry meta that could not be written earlier (see `on_submission()`).
	 */
	public function register_hooks() {
		add_filter( 'forminator_field_hidden_field_value', array( $this, 'render_field_value' ), 10, 4 );
		add_filter( 'forminator_prepared_data', array( $this, 'refresh_prepared_data' ), 10, 2 );
		add_filter( 'forminator_custom_form_submit_field_data', array( $this, 'protect_entry_fields' ), 10, 2 );
		add_action( 'forminator_custom_form_submit_before_set_fields', array( $this, 'on_submission' ), 10, 3 );
		add_action( 'forminator_after_handle_form', array( $this, 'flush_deferred_meta' ), 10, 1 );
	}

	/**
	 * Resolve the ClickTrail attribution key a Forminator Hidden field is marked with.
	 *
	 * A field is a ClickTrail field when its custom CSS class list carries a `ct_<key>` token
	 * (recommended), its label is exactly `ct_<key>`, or its default value is "Custom value" set to
	 * exactly `ct_<key>`, for a key in `Attribution_Provider::get_field_mapping()`. A visitor-typed
	 * `ct_company` or `ct_gclid note` is not a marker.
	 *
	 * @param mixed $field Formatted field settings (`custom-class`, `field_label`, `default_value`, `custom_value`).
	 * @return string|null Attribution key without the `ct_` prefix, or null when the field is not marked.
	 */
	private function field_key( $field ) {
		if ( ! is_array( $field ) ) {
			return null;
		}

		if ( ! empty( $field['custom-class'] ) && is_string( $field['custom-class'] ) ) {
			foreach ( preg_split( '/\s+/', trim( $field['custom-class'] ) ) as $token ) {
				$key = $this->mapped_key( $token );
				if ( null !== $key ) {
					return $key;
				}
			}
		}

		if ( ! empty( $field['field_label'] ) && is_string( $field['field_label'] ) ) {
			$key = $this->mapped_key( trim( $field['field_label'] ) );
			if ( null !== $key ) {
				return $key;
			}
		}

		if ( 'custom_value' === ( $field['default_value'] ?? '' ) && ! empty( $field['custom_value'] ) && is_string( $field['custom_value'] ) ) {
			return $this->mapped_key( trim( $field['custom_value'] ) );
		}

		return null;
	}

	/**
	 * Element ID to attribution key for every ClickTrail-marked Hidden field of a form.
	 *
	 * @param mixed $module Forminator form model.
	 * @return array<string,string>
	 */
	private function marked_fields( $module ) {
		if ( ! is_object( $module ) || ! method_exists( $module, 'get_fields' ) ) {
			return array();
		}

		$marked = array();
		foreach ( (array) $module->get_fields() as $field ) {
			if ( ! is_object( $field ) || ! method_exists( $field, 'to_formatted_array' ) ) {
				continue;
			}
			$settings = $field->to_formatted_array();
			if ( ! is_array( $settings ) || 'hidden' !== ( $settings['type'] ?? '' ) || empty( $settings['element_id'] ) || ! is_string( $settings['element_id'] ) ) {
				continue;
			}
			$key = $this->field_key( $settings );
			if ( null !== $key ) {
				$marked[ $settings['element_id'] ] = $key;
			}
		}

		return $marked;
	}

	/**
	 * Value of a marked Hidden field: empty, or the cookie value when the render filter opts in (#152).
	 *
	 * Forminator runs this filter at render and again while it handles the submitted Hidden fields; at
	 * submit `refresh_prepared_data()` overwrites the result with the submit-time cookie either way.
	 *
	 * @param mixed  $value       Value Forminator computed from the field's Default value setting.
	 * @param mixed  $saved_value Default value setting (unused).
	 * @param mixed  $field       Formatted field settings.
	 * @param object $field_obj   Forminator_Hidden instance (unused).
	 * @return mixed
	 */
	public function render_field_value( $value, $saved_value = '', $field = array(), $field_obj = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$key = $this->field_key( $field );
		if ( null === $key ) {
			return $value;
		}

		$value = '';
		if ( $this->render_server_values_enabled() && $this->refresh_allowed() ) {
			$cookie_value = $this->cookie_value_for( $this->get_field_name( $key ), $this->get_attribution_payload() );
			if ( null !== $cookie_value ) {
				$value = $cookie_value;
			}
		}

		return $value;
	}

	/**
	 * Resolve the marked Hidden fields at submit time (#152).
	 *
	 * Readable cookie: authoritative, keys it lacks are blank. No cookie: blank (Forminator inputs are
	 * not named `ct_*`, so there is no Injector marker). Consent blocked: blank. A blank value makes
	 * Forminator drop the field from the entry, so nothing stale is stored or mailed.
	 *
	 * @param mixed $prepared_data Posted data keyed by element ID.
	 * @param mixed $module        Forminator form model.
	 * @return mixed
	 */
	public function refresh_prepared_data( $prepared_data, $module = null ) {
		if ( ! is_array( $prepared_data ) ) {
			return $prepared_data;
		}

		$marked = $this->marked_fields( $module );
		if ( empty( $marked ) ) {
			return $prepared_data;
		}

		$resolved = $this->resolve_submitted_attribution( array(), false );
		foreach ( $marked as $element_id => $key ) {
			// A value Forminator would rewrite when it stores the entry is left out of the field; the entry meta
			// and ClickTrail's own log still carry it.
			$prepared_data[ $element_id ] = $this->forminator_would_rewrite( $resolved[ $key ] ) ? '' : $resolved[ $key ];
		}

		return $prepared_data;
	}

	/**
	 * Whether Forminator would change this value when it stores a Hidden field.
	 *
	 * For every Hidden field whose Default value is not Custom value / Embed ..., `handle_hidden_fields_after_entry_save()`
	 * stores `trim( forminator_replace_variables( '{' . $value . '}' ), '{}' )`, so a value that is a Forminator variable
	 * name (`user_ip`, `site_url`, `form_name`) or carries one (`{user_email}`) is replaced, and braces at either end are
	 * dropped (an unexpanded `{keyword}` ValueTrack template). The same call is made here to learn whether that happens.
	 *
	 * @param string $value Candidate field value.
	 * @return bool
	 */
	private function forminator_would_rewrite( $value ) {
		if ( '' === $value || ! function_exists( 'forminator_replace_variables' ) ) {
			return false;
		}

		return trim( (string) forminator_replace_variables( '{' . $value . '}' ), '{}' ) !== $value;
	}

	/**
	 * Keep Forminator from reapplying a Default value over a refreshed Hidden field.
	 *
	 * After this filter Forminator rewrites Hidden fields set to "Custom value" or "Embed Post/Page ..."
	 * back to their configured value. A marked field is ClickTrail's, so it is switched to the default that
	 * keeps the submitted value; the field settings in the entry data are a per-request copy.
	 *
	 * @param mixed $field_data_array Entry data about to be stored.
	 * @param mixed $form_id          Form ID (unused).
	 * @return mixed
	 */
	public function protect_entry_fields( $field_data_array, $form_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_array( $field_data_array ) ) {
			return $field_data_array;
		}

		foreach ( $field_data_array as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['field_array'] ) || ! is_array( $item['field_array'] ) ) {
				continue;
			}
			$settings = $item['field_array'];
			if ( 'hidden' !== ( $settings['type'] ?? '' ) || null === $this->field_key( $settings ) ) {
				continue;
			}
			if ( in_array( $settings['default_value'] ?? '', self::SERVER_OWNED_DEFAULTS, true ) ) {
				$field_data_array[ $index ]['field_array']['default_value'] = 'query';
			}
		}

		return $field_data_array;
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
	 * Log the submission and write ClickTrail's IDs and attribution to the Forminator entry meta.
	 *
	 * Runs once the entry row exists and before Forminator writes the field values. A form without a
	 * marked field still gets the cookie payload in ClickTrail's log and in the entry meta.
	 *
	 * @param mixed $entry            Forminator_Form_Entry_Model.
	 * @param mixed $form_id          Form ID.
	 * @param mixed $field_data_array Entry data.
	 * @return void
	 */
	public function on_submission( $entry, $form_id = 0, $field_data_array = array() ) {
		if ( ! is_object( $entry ) ) {
			return;
		}

		$status = isset( $entry->status ) && is_string( $entry->status ) ? $entry->status : '';
		if ( in_array( $status, self::NON_SUBMISSION_STATUSES, true ) ) {
			return;
		}

		// Submission-time consent is authoritative.
		if ( ! $this->refresh_allowed() ) {
			return;
		}

		$payload = $this->get_attribution_payload();
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return;
		}

		$form_id  = absint( $form_id );
		$entry_id = isset( $entry->entry_id ) ? absint( $entry->entry_id ) : 0;

		$identity = array();
		foreach ( (array) $field_data_array as $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) && array_key_exists( 'value', $item ) ) {
				$identity[ $item['name'] ] = $item['value'];
			}
		}

		// Log first: the entry meta carries the IDs it returns. A repeat of the same entry writes
		// only the IDs the entry lacks (the first call wrote the rest, or died before it could).
		$logged = $this->log_submission( 'forminator', $form_id, $payload, $identity, $entry_id );

		// Without a stored entry (Forminator's "do not store submissions") there is nothing to write to.
		if ( $entry_id < 1 || ! method_exists( $entry, 'set_fields' ) ) {
			return;
		}

		// A form_uid that matches an abandoned entry makes Forminator reuse that entry (`updating_entry`), and every
		// `set_fields()` call on it first deletes all of the entry's meta, including what is written here: Forminator
		// stores the fields right after this action. Wait until it has finished (`flush_deferred_meta()`).
		if ( ! empty( $entry->updating_entry ) ) {
			// A repeat for the same entry keeps the first call's result: it is the one that logged.
			if ( empty( $this->deferred[ $entry_id ] ) || ! empty( $this->deferred[ $entry_id ]['logged']['duplicate'] ) ) {
				$this->deferred[ $entry_id ] = array(
					'entry'   => $entry,
					'logged'  => $logged,
					'payload' => $payload,
				);
			}
			return;
		}

		$this->write_entry_meta( $entry, $logged, $payload );
	}

	/**
	 * Write the entry meta of the submissions `on_submission()` deferred.
	 *
	 * Fires at the end of `handle_form()`, after Forminator stored the entry fields, ran add-ons and sent mail. It does
	 * not fire when `handle_form()` returns earlier (a Stripe checkout response). That return also precedes
	 * `forminator_custom_form_submit_before_set_fields`, so `on_submission()` has not run for that request: nothing was
	 * deferred, no lead was logged and no entry meta was written.
	 *
	 * @param mixed $entry Forminator_Form_Entry_Model (unused; the stored objects are the same request's entries).
	 * @return void
	 */
	public function flush_deferred_meta( $entry = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$deferred       = $this->deferred;
		$this->deferred = array();

		foreach ( $deferred as $item ) {
			$this->write_entry_meta( $item['entry'], $item['logged'], $item['payload'] );
		}
	}

	/**
	 * Write `ct_<key>`, `ct_event_id` and `ct_lead_id` to a Forminator entry, once.
	 *
	 * @param object $entry   Forminator_Form_Entry_Model.
	 * @param mixed  $logged  `log_submission()` result.
	 * @param array  $payload Attribution payload that was logged.
	 * @return void
	 */
	private function write_entry_meta( $entry, $logged, array $payload ) {
		$meta = $this->entry_meta_to_write(
			$logged,
			$payload,
			function ( $meta_key ) use ( $entry ) {
				return $this->entry_has_meta( $entry, $meta_key );
			}
		);

		$rows = array();
		foreach ( $meta as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$rows[] = array(
					'name'  => $this->get_field_name( $key ),
					'value' => (string) $value,
				);
			}
		}
		if ( empty( $rows ) ) {
			return;
		}

		// `set_fields()` on an entry that is being updated deletes the entry's other meta first; this write adds rows only.
		$updating = ! empty( $entry->updating_entry );
		if ( $updating ) {
			$entry->updating_entry = false;
		}
		$entry->set_fields( $rows );
		if ( $updating ) {
			$entry->updating_entry = true;
		}
	}

	/**
	 * Whether a Forminator entry already holds a meta row with this key.
	 *
	 * A model that cannot answer counts as "yes", so a repeat never writes a second row it cannot rule out.
	 *
	 * @param object $entry    Forminator_Form_Entry_Model.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private function entry_has_meta( $entry, $meta_key ) {
		if ( ! method_exists( $entry, 'get_meta' ) ) {
			return true;
		}

		// Third argument: read the database, not the request's in-memory copy (Forminator 1.58+; older
		// versions ignore it and answer from memory).
		return false !== $entry->get_meta( $meta_key, false, true );
	}
}
