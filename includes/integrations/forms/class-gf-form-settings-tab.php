<?php
/**
 * Gravity Forms — Per-form Settings Tab
 *
 * Owns the per-form "Attribution Tracking" toggle in the GF form settings
 * page, the save handler, and the per-form gate that other components
 * (`Gf_Merge_Tags`, `Gravity_Forms_Adapter::populate_fields_dynamic`,
 * `::on_submission`) consult before writing or reading attribution.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Settings\Attribution_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gf_Form_Settings_Tab
 */
class Gf_Form_Settings_Tab {

	/**
	 * Memoized Attribution_Settings (shared with the adapter via constructor).
	 *
	 * @var Attribution_Settings
	 */
	private $settings;

	/**
	 * Constructor — self-registers the GF settings filters.
	 *
	 * @param Attribution_Settings $settings Shared Attribution_Settings instance.
	 */
	public function __construct( Attribution_Settings $settings ) {
		$this->settings = $settings;

		add_filter( 'gform_form_settings', array( $this, 'add_section' ), 10, 2 );
		// GF 2.5+ passes only $form to this filter; the single-arg signature is intentional.
		// GF 2.4 passed ($form, $form_id), but extra args are ignored by add_filter.
		add_filter( 'gform_pre_form_settings_save', array( $this, 'save' ), 10, 1 );
	}

	/**
	 * Render the ClickTrail section in the GF form settings page.
	 *
	 * @param array $settings Existing form settings sections.
	 * @param array $form     Current form object.
	 * @return array
	 */
	public function add_section( $settings, $form ) {
		$form_id = is_array( $form ) && isset( $form['id'] ) ? absint( $form['id'] ) : 0;
		if ( 0 === $form_id ) {
			return $settings;
		}

		// Per-form settings are stored directly in the form object (not entry meta).
		if ( is_array( $form ) && isset( $form['clicutcl_tracking_enabled'] ) && '' !== (string) $form['clicutcl_tracking_enabled'] ) {
			$is_enabled = '1' === (string) $form['clicutcl_tracking_enabled'];
		} else {
			$is_enabled = $this->settings->is_gf_tracking_default_enabled();
		}

		$checked_attr = $is_enabled ? ' checked="checked"' : '';

		// Wrap in <table> so the row renders regardless of whether the current
		// GF version wraps legacy-filter content in a table container itself.
		$settings['ClickTrail'] =
			'<table class="gform_settings_fields"><tbody>' .
			'<tr>' .
			'<th>' . esc_html__( 'Attribution Tracking', 'click-trail-handler' ) . '</th>' .
			'<td>' .
			'<label>' .
			'<input type="checkbox" name="clicutcl_tracking_enabled" value="1"' . $checked_attr . ' />' .
			' ' . esc_html__( 'Enable attribution tracking for this form', 'click-trail-handler' ) .
			'</label>' .
			'</td>' .
			'</tr>' .
			'</tbody></table>';

		return $settings;
	}

	/**
	 * Persist the tracking preference into the form object.
	 *
	 * `gform_pre_form_settings_save` passes the full form array as its only
	 * argument. Per-form settings must be stored as top-level keys in that
	 * array so GF saves them alongside the rest of the form meta. Reading the
	 * submitted checkbox via `rgpost()` is correct here; GF handles the nonce
	 * and sanitisation before this filter fires.
	 *
	 * @param array $form Current form object.
	 * @return array Modified form object.
	 */
	public function save( $form ) {
		if ( ! is_array( $form ) ) {
			return $form;
		}
		$form['clicutcl_tracking_enabled'] = \rgpost( 'clicutcl_tracking_enabled' ) ? '1' : '0';
		return $form;
	}

	/**
	 * Determine whether attribution tracking is enabled for a specific form.
	 *
	 * Per-form preferences live in `$form['clicutcl_tracking_enabled']`. Falls
	 * back to the global default when no per-form setting has been saved.
	 * Applies the `clicutcl_gf_tracking_enabled` filter for dev overrides.
	 *
	 * @param int        $form_id Form ID.
	 * @param array|null $form    Full form object, when available (skips DB lookup).
	 * @return bool
	 */
	public function is_enabled( $form_id, $form = null ) {
		$raw_value = '';

		if ( is_array( $form ) && isset( $form['clicutcl_tracking_enabled'] ) ) {
			$raw_value = (string) $form['clicutcl_tracking_enabled'];
		} elseif ( class_exists( 'GFAPI' ) ) {
			$fetched = \GFAPI::get_form( $form_id );
			if ( is_array( $fetched ) && isset( $fetched['clicutcl_tracking_enabled'] ) ) {
				$raw_value = (string) $fetched['clicutcl_tracking_enabled'];
			}
		}

		if ( '' === $raw_value ) {
			$enabled = $this->settings->is_gf_tracking_default_enabled();
		} else {
			$enabled = '1' === $raw_value;
		}

		return (bool) apply_filters( 'clicutcl_gf_tracking_enabled', $enabled, $form_id, $form );
	}
}
