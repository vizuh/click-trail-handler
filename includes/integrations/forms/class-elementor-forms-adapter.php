<?php
/**
 * Elementor Forms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Elementor_Forms_Adapter
 */
class Elementor_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Check if Elementor Pro Forms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\ElementorPro\Modules\Forms\Module' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Elementor Forms';
	}

	/**
	 * Register hooks.
	 *
	 * Render: `elementor_pro/forms/render/item/hidden` runs after Form_Base set the input's
	 * render attributes. Submit: `elementor_pro/forms/process` runs after validation and before
	 * the form actions (email, webhook, Submissions), so a refresh there reaches all of them;
	 * `elementor_pro/forms/new_record` runs after the actions and only logs.
	 */
	public function register_hooks() {
		add_filter( 'elementor_pro/forms/render/item/hidden', array( $this, 'render_hidden_field' ), 10, 3 );
		add_action( 'elementor_pro/forms/process', array( $this, 'refresh_record' ), 10, 2 );
		add_action( 'elementor_pro/forms/new_record', array( $this, 'on_submission' ), 10, 2 );
	}

	/**
	 * Fill a hidden field whose custom ID is `ct_<key>` from the attribution cookie at render,
	 * only when `clicutcl_render_server_attribution_values` opts in (cached HTML, #152).
	 *
	 * Elementor names the input `form_fields[<custom_id>]`, so the browser runtime's exact
	 * `name="ct_*"` match cannot fill it; the server does. Form_Base already added the `value`
	 * attribute from `field_value`, so it is replaced, not appended.
	 *
	 * @param array  $item       Field item.
	 * @param int    $item_index Field index.
	 * @param object $form       Form widget instance.
	 * @return array
	 */
	public function render_hidden_field( $item, $item_index, $form = null ) {
		if ( ! is_array( $item ) || empty( $item['custom_id'] ) || ! $this->render_server_values_enabled() || ! $this->refresh_allowed() ) {
			return $item;
		}

		$value = $this->cookie_value_for( (string) $item['custom_id'], $this->get_attribution_payload() );
		if ( null === $value ) {
			return $item;
		}

		$item['field_value'] = $value;
		if ( is_object( $form ) && method_exists( $form, 'set_render_attribute' ) ) {
			$form->set_render_attribute( 'input' . $item_index, 'value', $value );
		}

		return $item;
	}

	/**
	 * Resolve each `ct_<key>` record field at submit time (#152).
	 *
	 * Readable cookie: authoritative, keys it lacks are blanked. No cookie: blanked (Elementor
	 * inputs are not named `ct_*`, so no Injector marker). Consent blocked: blanked. Fields the
	 * form does not carry are never added.
	 *
	 * @param object $record       Form_Record instance.
	 * @param object $ajax_handler Ajax handler (unused).
	 * @return void
	 */
	public function refresh_record( $record, $ajax_handler = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) || ! method_exists( $record, 'update_field' ) ) {
			return;
		}

		$fields = $record->get( 'fields' );
		if ( ! is_array( $fields ) ) {
			return;
		}

		$posted = array();
		foreach ( $fields as $field_id => $field ) {
			$key = $this->mapped_key( $field_id );
			if ( null !== $key ) {
				$posted[ $key ] = is_array( $field ) ? ( $field['value'] ?? '' ) : '';
			}
		}
		if ( empty( $posted ) ) {
			return;
		}

		$resolved = $this->resolve_submitted_attribution( $posted, false );
		foreach ( array_keys( $posted ) as $key ) {
			$record->update_field( $this->get_field_name( $key ), 'value', $resolved[ $key ] );
		}
	}

	/**
	 * Interface compliance.
	 *
	 * @param mixed $form_or_context Form context.
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Handle Elementor form submissions.
	 *
	 * @param mixed $arg1 Form record object.
	 * @param mixed $arg2 Ajax handler object.
	 * @return void
	 */
	public function on_submission( $arg1, $arg2 = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! $this->should_populate() ) {
			return;
		}

		$record = $arg1;
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$raw_fields  = $record->get( 'fields' );
		$fields      = $this->extract_record_fields( $record );
		$payload     = $this->get_attribution_payload();
		$attribution = array();

		// The rule refresh_record() applied before the actions ran, re-applied (pure, idempotent).
		$posted = array();
		if ( is_array( $raw_fields ) ) {
			foreach ( array_keys( $raw_fields ) as $field_id ) {
				$prefixed = (string) $field_id;
				$key      = $this->mapped_key( $prefixed );
				if ( null !== $key ) {
					$posted[ $key ] = $fields[ sanitize_key( $prefixed ) ] ?? '';
				}
			}
		}
		$carries_ct_field = ! empty( $posted );
		if ( $carries_ct_field ) {
			foreach ( $this->resolve_submitted_attribution( $posted, false ) as $key => $value ) {
				if ( '' !== $value && array_key_exists( $key, $posted ) ) {
					$attribution[ $key ] = $value;
				}
			}
		}

		// A form with no ct_* field keeps today's record: ClickTrail's own log holds the cookie.
		if ( ! $carries_ct_field ) {
			$attribution = $payload;
		}

		if ( empty( $attribution ) ) {
			return;
		}

		$form_id = $this->resolve_form_identifier( $record, $fields );
		$this->log_submission( 'elementor_forms', $form_id, $attribution, $fields );
	}

	/**
	 * Flatten Elementor record fields into a scalar map keyed by field ID and label.
	 *
	 * @param object $record Elementor record object.
	 * @return array
	 */
	private function extract_record_fields( $record ) {
		$raw_fields = $record->get( 'fields' );
		if ( ! is_array( $raw_fields ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw_fields as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$value = $this->normalize_field_value(
				$field['value'] ?? ( $field['raw_value'] ?? '' )
			);
			if ( '' === $value ) {
				continue;
			}

			$keys   = array();
			$keys[] = sanitize_key( (string) $field_id );

			if ( ! empty( $field['id'] ) && is_scalar( $field['id'] ) ) {
				$keys[] = sanitize_key( (string) $field['id'] );
			}
			if ( ! empty( $field['custom_id'] ) && is_scalar( $field['custom_id'] ) ) {
				$keys[] = sanitize_key( (string) $field['custom_id'] );
			}
			if ( ! empty( $field['title'] ) && is_scalar( $field['title'] ) ) {
				$keys[] = sanitize_key( (string) $field['title'] );
			}
			if ( ! empty( $field['label'] ) && is_scalar( $field['label'] ) ) {
				$keys[] = sanitize_key( (string) $field['label'] );
			}

			foreach ( array_unique( array_filter( $keys ) ) as $key ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Resolve a stable form identifier for logging.
	 *
	 * @param object $record Elementor record object.
	 * @param array  $fields Flattened field map.
	 * @return string
	 */
	private function resolve_form_identifier( $record, $fields ) {
		$candidates = array();

		if ( method_exists( $record, 'get_form_settings' ) ) {
			$candidates[] = $record->get_form_settings( 'id' );
			$candidates[] = $record->get_form_settings( 'form_id' );
			$candidates[] = $record->get_form_settings( 'form_name' );
		}

		$candidates[] = $fields['form_id'] ?? '';
		$candidates[] = $fields['form_name'] ?? '';

		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $candidate );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return 'elementor_form';
	}

	/**
	 * Normalize Elementor field values to a flat string.
	 *
	 * @param mixed $value Raw field value.
	 * @return string
	 */
	private function normalize_field_value( $value ) {
		if ( is_array( $value ) ) {
			$value = implode(
				', ',
				array_filter(
					array_map(
						static function ( $item ) {
							return is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '';
						},
						$value
					)
				)
			);
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}
}
