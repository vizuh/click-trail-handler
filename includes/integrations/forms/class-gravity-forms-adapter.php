<?php
/**
 * Gravity Forms Adapter
 *
 * Thin coordinator: registers GF hooks that need adapter-level context
 * (entry meta, dynamic populate, submission persistence, entry-edit safety)
 * and delegates the rest to extracted helpers (`Gf_Channel_Resolver`,
 * `Gf_Form_Settings_Tab`, `Gf_Merge_Tags`, `Gf_Minification_Protector`).
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;
use CLICUTCL\Settings\Attribution_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gravity_Forms_Adapter
 */
class Gravity_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Memoized Attribution_Settings instance (M-2).
	 *
	 * @var Attribution_Settings|null
	 */
	private $settings_cache = null;

	/**
	 * Memoized channel resolver.
	 *
	 * @var Gf_Channel_Resolver|null
	 */
	private $channel_resolver = null;

	/**
	 * Per-form settings tab helper (also owns the per-form gate).
	 *
	 * @var Gf_Form_Settings_Tab|null
	 */
	private $settings_tab = null;

	/**
	 * Lazy getter for the shared Attribution_Settings instance.
	 *
	 * @return Attribution_Settings
	 */
	private function get_settings(): Attribution_Settings {
		if ( null === $this->settings_cache ) {
			$this->settings_cache = new Attribution_Settings();
		}
		return $this->settings_cache;
	}

	/**
	 * Lazy getter for the channel resolver.
	 *
	 * @return Gf_Channel_Resolver
	 */
	private function get_channel_resolver(): Gf_Channel_Resolver {
		if ( null === $this->channel_resolver ) {
			$this->channel_resolver = new Gf_Channel_Resolver();
		}
		return $this->channel_resolver;
	}

	/**
	 * Check if Gravity Forms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'GFForms' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Gravity Forms';
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_filter( 'gform_entry_meta', array( $this, 'register_entry_meta' ), 10, 2 );
		add_filter( 'gform_field_value', array( $this, 'populate_fields_dynamic' ), 10, 3 );
		add_action( 'gform_after_submission', array( $this, 'on_submission' ), 10, 2 );
		add_action( 'gform_after_update_entry', array( $this, 'restore_tracking_meta_after_edit' ), 10, 3 );

		// Extracted helpers — each self-registers in its constructor.
		$this->settings_tab = new Gf_Form_Settings_Tab( $this->get_settings() );
		new Gf_Merge_Tags( $this->settings_tab );
		new Gf_Minification_Protector();
	}

	/**
	 * Declare ClickTrail attribution keys as Gravity Forms entry meta.
	 *
	 * @param array $entry_meta Existing entry meta definitions.
	 * @param int   $form_id    Current form ID (unused; we register for all forms).
	 * @return array
	 */
	public function register_entry_meta( $entry_meta, $form_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$keys = Attribution_Provider::get_field_mapping();
		foreach ( $keys as $key ) {
			$meta_key                = $this->get_field_name( $key );
			$entry_meta[ $meta_key ] = array(
				'label'             => 'ClickTrail: ' . ucwords( str_replace( '_', ' ', $key ) ),
				'is_numeric'        => false,
				'is_default_column' => false,
			);
		}
		return $entry_meta;
	}

	/**
	 * Populate fields dynamically.
	 *
	 * @param string $value The field value.
	 * @param object $field The field object.
	 * @param string $name  The parameter name.
	 * @return string Modified value.
	 */
	public function populate_fields_dynamic( $value, $field, $name ) {
		if ( 0 !== strpos( $name, $this->field_prefix ) ) {
			return $value;
		}
		if ( ! $this->should_populate() ) {
			return $value;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- GF core property.
		$form_id = isset( $field->formId ) ? absint( $field->formId ) : 0;
		// Fail-open when form context is unavailable: continue with default behavior rather than break the integration.
		if ( 0 !== $form_id && null !== $this->settings_tab && ! $this->settings_tab->is_enabled( $form_id ) ) {
			return $value;
		}

		$payload = $this->get_attribution_payload();
		$key     = substr( $name, strlen( $this->field_prefix ) );

		return isset( $payload[ $key ] ) ? $payload[ $key ] : $value;
	}

	/**
	 * Placeholder for interface compliance. GF uses its own filter signature.
	 *
	 * @param mixed $form_or_context Form or context data (passed through unchanged).
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Handle form submission: persist attribution to entry meta and log.
	 *
	 * @param array $arg1 Entry data.
	 * @param array $arg2 Form object.
	 */
	public function on_submission( $arg1, $arg2 ) {
		$entry = $arg1;
		$form  = $arg2;

		if ( null !== $this->settings_tab && ! $this->settings_tab->is_enabled( absint( $form['id'] ), $form ) ) {
			return;
		}

		$payload = $this->get_attribution_payload();
		if ( empty( $payload ) ) {
			return;
		}

		// Server-side channel fallback when JS attribution was unavailable.
		if ( empty( $payload['ft_channel'] ) ) {
			$payload['ft_channel'] = $this->resolve_channel_fallback( $payload );
		}

		$payload['ft_channel'] = (string) apply_filters(
			'clicutcl_gf_channel_label',
			$payload['ft_channel'],
			$payload,
			$entry,
			$form
		);

		if ( '' === $payload['ft_channel'] ) {
			$payload['ft_channel'] = 'Unknown';
		}

		if ( empty( $payload['lt_channel'] ) ) {
			$payload['lt_channel'] = $this->resolve_channel_fallback( $payload );
		}

		$payload['lt_channel'] = (string) apply_filters(
			'clicutcl_gf_channel_label',
			$payload['lt_channel'],
			$payload,
			$entry,
			$form
		);

		if ( '' === $payload['lt_channel'] ) {
			$payload['lt_channel'] = 'Unknown';
		}

		foreach ( $payload as $key => $value ) {
			\gform_add_meta( $entry['id'], $this->get_field_name( $key ), $value );
		}

		$this->log_submission( 'gravityforms', $form['id'], $payload, $this->extract_identity_from_entry( $entry, $form ) );
	}

	/**
	 * Backward-compat shim: delegates to the extracted Gf_Channel_Resolver.
	 *
	 * Kept public so any external code that called the previous method still
	 * works. New code should depend on `Gf_Channel_Resolver` directly.
	 *
	 * @param array $payload Attribution payload.
	 * @return string Channel label.
	 */
	public function resolve_channel_fallback( array $payload ): string {
		$current_host = '';
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
			$current_host = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) );
		}
		return $this->get_channel_resolver()->resolve( $payload, '', $current_host );
	}

	/**
	 * Extract email/phone candidates from a Gravity entry using field metadata.
	 *
	 * @param array $entry Entry data.
	 * @param array $form  Form object.
	 * @return array
	 */
	private function extract_identity_from_entry( $entry, $form ) {
		if ( ! is_array( $entry ) || ! is_array( $form ) || empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return array();
		}

		$identity = array();

		foreach ( $form['fields'] as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}

			$field_id = isset( $field->id ) ? (string) $field->id : '';
			if ( '' === $field_id || ! isset( $entry[ $field_id ] ) || ! is_scalar( $entry[ $field_id ] ) ) {
				continue;
			}

			$value = trim( (string) $entry[ $field_id ] );
			if ( '' === $value ) {
				continue;
			}

			$type = '';
			if ( method_exists( $field, 'get_input_type' ) ) {
				$type = sanitize_key( (string) $field->get_input_type() );
			}
			if ( '' === $type && isset( $field->type ) ) {
				$type = sanitize_key( (string) $field->type );
			}

			$label = '';
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- GF core property.
			if ( isset( $field->adminLabel ) && is_scalar( $field->adminLabel ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- GF core property.
				$label = strtolower( (string) $field->adminLabel );
			} elseif ( isset( $field->label ) && is_scalar( $field->label ) ) {
				$label = strtolower( (string) $field->label );
			}

			if ( empty( $identity['email'] ) && ( 'email' === $type || false !== strpos( $label, 'email' ) ) && is_email( $value ) ) {
				$identity['email'] = sanitize_email( $value );
			}

			if ( empty( $identity['phone'] ) && ( 'phone' === $type || $this->is_phone_candidate( $label, $value ) ) ) {
				$identity['phone'] = sanitize_text_field( $value );
			}
		}

		return $identity;
	}

	/**
	 * Remove ClickTrail attribution fields from editable entry meta boxes.
	 *
	 * @param array $meta_boxes Registered meta box definitions.
	 * @param array $form       Current form object (unused).
	 * @param array $entry      Current entry object (unused).
	 * @return array
	 */
	public function exclude_fields_from_edit( $meta_boxes, $form, $entry ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $meta_boxes ) ) {
			return $meta_boxes;
		}

		$prefix = $this->field_prefix;

		foreach ( $meta_boxes as $box_key => $meta_box ) {
			if ( ! is_array( $meta_box ) || empty( $meta_box['fields'] ) || ! is_array( $meta_box['fields'] ) ) {
				continue;
			}

			$filtered = array();
			foreach ( $meta_box['fields'] as $field ) {
				if ( is_array( $field ) && isset( $field['meta_key'] ) && 0 === strpos( (string) $field['meta_key'], $prefix ) ) {
					continue;
				}
				$filtered[] = $field;
			}

			$meta_boxes[ $box_key ]['fields'] = $filtered;
		}

		return $meta_boxes;
	}

	/**
	 * Restore any ct_* entry meta cleared during a manual entry edit.
	 *
	 * @param array $form           Current form object (unused).
	 * @param int   $entry_id       Entry ID.
	 * @param array $original_entry Entry state before the update.
	 */
	public function restore_tracking_meta_after_edit( $form, $entry_id, $original_entry ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! is_array( $original_entry ) ) {
			return;
		}

		foreach ( Attribution_Provider::get_field_mapping() as $key ) {
			$meta_key = $this->get_field_name( $key );

			if ( ! isset( $original_entry[ $meta_key ] ) || '' === (string) $original_entry[ $meta_key ] ) {
				continue;
			}

			$current = \gform_get_meta( $entry_id, $meta_key );
			if ( '' === (string) $current || null === $current ) {
				\gform_update_meta( $entry_id, $meta_key, $original_entry[ $meta_key ] );
			}
		}
	}
}
