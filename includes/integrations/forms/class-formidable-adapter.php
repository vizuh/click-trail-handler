<?php
/**
 * Formidable Forms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Formidable_Adapter
 *
 * Formidable posts every field as `item_meta[<field id>]`, so no input carries a `ct_*` name. A
 * hidden field is ClickTrail's when its field key is `ct_<key>` (Formidable keeps field keys unique
 * across all forms and appends a number to a repeat: `ct_gclid`, `ct_gclid2`) or its label is
 * exactly `ct_<key>`, for a key in `Attribution_Provider::get_field_mapping()`. The browser Injector
 * finds inputs by name, so it never fills these fields and never writes the fill marker: the
 * submit-time rule is the cookie sent with the submit, or blank.
 */
class Formidable_Adapter extends Abstract_Form_Adapter {

	/**
	 * Check if Formidable Forms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'FrmAppHelper' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Formidable Forms';
	}

	/**
	 * Register hooks.
	 *
	 * `frm_get_default_value` fills a marked hidden field at render (only when
	 * `clicutcl_render_server_attribution_values` opts in, #152). `frm_pre_create_entry` hands the
	 * entry values to the filter right before they are packaged and stored, so the stored entry,
	 * the notification emails (built from the stored entry) and ClickTrail's log hold one value.
	 * `frm_after_create_entry` runs once per stored entry and carries the entry ID. None of these
	 * hooks is deprecated in the Formidable 7.0 source.
	 */
	public function register_hooks() {
		add_filter( 'frm_get_default_value', array( $this, 'render_field_default' ), 20, 4 );
		add_filter( 'frm_pre_create_entry', array( $this, 'refresh_entry_values' ), 10, 1 );
		add_action( 'frm_after_create_entry', array( $this, 'on_submission' ), 10, 3 );
	}

	/**
	 * Attribution key a Formidable field is marked with.
	 *
	 * Only `hidden` fields count. The field key wins over the label; a field key may carry the
	 * numeric suffix Formidable appends to keep keys unique. A label must be exactly `ct_<key>`
	 * (`ct_gclid note` is a label, not a marker), and the key must be in `get_field_mapping()`.
	 *
	 * @param object|array $field Formidable field (`type`, `field_key`, `name`).
	 * @return string|null Unprefixed attribution key, or null when the field is not marked.
	 */
	private function field_key_for( $field ) {
		$field = is_array( $field ) ? (object) $field : $field;
		if ( ! is_object( $field ) || ! isset( $field->type ) || 'hidden' !== $field->type ) {
			return null;
		}

		$field_key = isset( $field->field_key ) && is_string( $field->field_key ) ? strtolower( $field->field_key ) : '';
		$key       = $this->mapped_key( $field_key );
		if ( null === $key ) {
			$key = $this->mapped_key( (string) preg_replace( '/\d+$/', '', $field_key ) );
		}
		if ( null === $key ) {
			$key = $this->mapped_key( isset( $field->name ) && is_string( $field->name ) ? trim( $field->name ) : '' );
		}

		return $key;
	}

	/**
	 * Whether a Formidable field is a ClickTrail attribution field (the rule above, for Diagnostics).
	 *
	 * @param object|array $field Formidable field (`type`, `field_key`, `name`).
	 * @return bool
	 */
	public function is_marked_field( $field ) {
		return null !== $this->field_key_for( $field );
	}

	/**
	 * Fields of a form.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int|string,object>
	 */
	private function form_fields( $form_id ) {
		if ( $form_id < 1 || ! class_exists( 'FrmField' ) || ! method_exists( 'FrmField', 'get_all_for_form' ) ) {
			return array();
		}

		$fields = \FrmField::get_all_for_form( $form_id );

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Marked fields of a form, indexed by every reference an entry may use for them.
	 *
	 * `item_meta` is keyed by field ID, and Formidable also accepts the field key there.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string,string> Field ID and field key to unprefixed attribution key.
	 */
	private function marked_fields( $form_id ) {
		$marked = array();

		foreach ( $this->form_fields( $form_id ) as $field ) {
			$key = $this->field_key_for( $field );
			if ( null === $key ) {
				continue;
			}
			if ( isset( $field->id ) && is_scalar( $field->id ) ) {
				$marked[ (string) $field->id ] = $key;
			}
			if ( isset( $field->field_key ) && is_string( $field->field_key ) && '' !== $field->field_key ) {
				$marked[ $field->field_key ] = $key;
			}
		}

		return $marked;
	}

	/**
	 * Whether the request is an admin screen (entry add or import), not a front-end submission.
	 *
	 * Formidable submits through admin-ajax.php too, so AJAX requests are front-end. An admin's own
	 * cookie must never fill or be logged for an entry created on their behalf.
	 *
	 * @return bool
	 */
	private function is_admin_screen() {
		return is_admin() && ! wp_doing_ajax();
	}

	/**
	 * Fill a marked hidden field from the attribution cookie at render, only when
	 * `clicutcl_render_server_attribution_values` opts in (cached HTML, #152).
	 *
	 * Formidable runs `frm_get_default_value` for field defaults (`$dynamic_default` true) and for
	 * placeholders and field HTML (false); only the former is a value.
	 *
	 * @param mixed        $value           Default value.
	 * @param object|array $field           Field (`type`, `field_key`, `name`).
	 * @param bool         $dynamic_default Whether this is the field default.
	 * @param bool         $return_array    Whether the field takes an array (unused).
	 * @return mixed
	 */
	public function render_field_default( $value, $field = null, $dynamic_default = true, $return_array = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! $dynamic_default || ! $this->render_server_values_enabled() || ! $this->refresh_allowed() ) {
			return $value;
		}

		$key = $this->field_key_for( $field );
		if ( null === $key ) {
			return $value;
		}

		$cookie_value = $this->cookie_value_for( $this->get_field_name( $key ), $this->get_attribution_payload() );

		return null === $cookie_value ? $value : $cookie_value;
	}

	/**
	 * Resolve the marked fields of an entry about to be stored (#152).
	 *
	 * Runs on `frm_pre_create_entry`, whose callback receives the values Formidable is about to
	 * package and store and returns them (`FrmEntry::before_insert_entry_in_database()`); it runs
	 * for every standard entry, AJAX or not, before `frm_after_create_entry` and the email actions.
	 * Readable cookie: authoritative, keys it lacks are blanked. No cookie: blanked (Formidable
	 * inputs carry no Injector marker). Consent blocked: blanked. Only marked fields the form
	 * posted are rewritten; `item_meta` entries of other fields are untouched.
	 *
	 * Formidable stores `item_meta` as it arrives in `$_POST`, still slashed, and unslashes on read,
	 * so the written value is slashed the same way.
	 *
	 * @param array $values Entry values (`form_id`, `item_meta`).
	 * @return array
	 */
	public function refresh_entry_values( $values ) {
		if ( ! is_array( $values ) || empty( $values['item_meta'] ) || ! is_array( $values['item_meta'] ) || $this->is_admin_screen() ) {
			return $values;
		}

		$marked = $this->marked_fields( isset( $values['form_id'] ) ? absint( $values['form_id'] ) : 0 );
		if ( empty( $marked ) ) {
			return $values;
		}

		$posted = array();
		$slots  = array();
		foreach ( $values['item_meta'] as $reference => $value ) {
			$reference = (string) $reference;
			if ( ! isset( $marked[ $reference ] ) ) {
				continue;
			}
			$slots[ $reference ]             = $marked[ $reference ];
			$posted[ $marked[ $reference ] ] = $value;
		}
		if ( empty( $slots ) ) {
			return $values;
		}

		$resolved = $this->resolve_submitted_attribution( $posted, false );
		foreach ( $slots as $reference => $key ) {
			$values['item_meta'][ $reference ] = wp_slash( $resolved[ $key ] );
		}

		return $values;
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
	 * Handle a stored entry (log to ClickTrail and write entry meta).
	 *
	 * Skips drafts, spam, child (repeater) entries and admin-screen entries, none of which is a
	 * front-end submission. ClickTrail's own lead carries the full consented cookie payload, overlaid
	 * by the refreshed values of the marked fields.
	 *
	 * @param int|string $entry_id Entry ID.
	 * @param int|string $form_id  Form ID.
	 * @param array      $args     Hook arguments (`is_child`).
	 */
	public function on_submission( $entry_id, $form_id = 0, $args = array() ) {
		if ( ! $this->refresh_allowed() || $this->is_admin_screen() || ( is_array( $args ) && ! empty( $args['is_child'] ) ) ) {
			return;
		}

		$entry_id = absint( $entry_id );
		$form_id  = absint( $form_id );
		$entry    = $this->load_entry( $entry_id );
		if ( $entry_id < 1 || ! is_object( $entry ) || 0 !== (int) ( $entry->is_draft ?? 0 ) ) {
			return;
		}

		$payload     = $this->get_attribution_payload();
		$attribution = is_array( $payload ) ? $payload : array();

		$marked = $this->marked_fields( $form_id );
		if ( ! empty( $marked ) ) {
			$posted = array();
			foreach ( $marked as $key ) {
				$posted[ $key ] = '';
			}
			foreach ( $this->resolve_submitted_attribution( $posted, false ) as $key => $value ) {
				if ( '' !== $value && array_key_exists( $key, $posted ) ) {
					$attribution[ $key ] = $value;
				}
			}
		}

		// Log first: the entry meta carries the IDs it returns. A repeat of the same entry writes
		// only the IDs the entry lacks (the first call wrote the rest, or died before it could).
		$logged = $this->log_submission( 'formidable', $form_id, $attribution, $this->identity_input( $entry, $form_id ), $entry_id );
		$meta   = $this->entry_meta_to_write(
			$logged,
			$attribution,
			function ( $meta_key ) use ( $entry_id ) {
				return $this->entry_has_meta( $entry_id, $meta_key );
			}
		);

		if ( empty( $meta ) || ! class_exists( 'FrmEntryMeta' ) || ! method_exists( 'FrmEntryMeta', 'add_entry_meta' ) ) {
			return;
		}

		// Formidable keeps non-field entry data (spam score, duplicate-check ID) as a `field_id` 0 row
		// holding a serialized array; one row carries every pair.
		$row = array();
		foreach ( $meta as $key => $value ) {
			$row[ $this->get_field_name( $key ) ] = (string) $value;
		}
		\FrmEntryMeta::add_entry_meta( $entry_id, 0, '', $row );
	}

	/**
	 * Load a stored entry with its field values.
	 *
	 * @param int $entry_id Entry ID.
	 * @return object|null
	 */
	private function load_entry( $entry_id ) {
		if ( $entry_id < 1 || ! class_exists( 'FrmEntry' ) || ! method_exists( 'FrmEntry', 'getOne' ) ) {
			return null;
		}

		$entry = \FrmEntry::getOne( $entry_id, true );

		return is_object( $entry ) ? $entry : null;
	}

	/**
	 * Whether an entry already holds a ClickTrail meta key.
	 *
	 * A failed lookup answers "yes", so a repeat never writes a pair it cannot rule out.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $meta_key Prefixed meta key, e.g. `ct_event_id`.
	 * @return bool
	 */
	private function entry_has_meta( $entry_id, $meta_key ) {
		if ( ! class_exists( 'FrmEntryMeta' ) || ! method_exists( 'FrmEntryMeta', 'get_entry_meta_info' ) ) {
			return true;
		}

		$rows = \FrmEntryMeta::get_entry_meta_info( $entry_id );
		if ( ! is_array( $rows ) ) {
			return true;
		}

		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || 0 !== (int) ( $row->field_id ?? -1 ) ) {
				continue;
			}
			$value = maybe_unserialize( $row->meta_value ?? '' );
			if ( is_array( $value ) && array_key_exists( $meta_key, $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Email/phone candidates from a stored entry's field values.
	 *
	 * @param object $entry   Entry with `metas` (field ID to value).
	 * @param int    $form_id Form ID.
	 * @return array
	 */
	private function identity_input( $entry, $form_id ) {
		$metas = isset( $entry->metas ) && is_array( $entry->metas ) ? $entry->metas : array();
		if ( empty( $metas ) ) {
			return array();
		}

		$identity = array();
		foreach ( $this->form_fields( $form_id ) as $field ) {
			if ( ! isset( $field->id, $metas[ $field->id ] ) || ! is_scalar( $metas[ $field->id ] ) ) {
				continue;
			}
			$value = trim( (string) $metas[ $field->id ] );
			if ( '' === $value ) {
				continue;
			}

			$type = isset( $field->type ) ? (string) $field->type : '';
			if ( 'email' === $type && empty( $identity['email'] ) ) {
				$identity['email'] = $value;
			} elseif ( 'phone' === $type && empty( $identity['phone'] ) ) {
				$identity['phone'] = $value;
			} else {
				$name = isset( $field->name ) && is_string( $field->name ) && '' !== $field->name ? $field->name : ( $field->field_key ?? '' );
				$slug = sanitize_key( (string) $name );
				if ( '' !== $slug && ! isset( $identity[ $slug ] ) ) {
					$identity[ $slug ] = $value;
				}
			}
		}

		return $identity;
	}
}
