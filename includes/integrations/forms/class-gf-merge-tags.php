<?php
/**
 * Gravity Forms Merge Tags
 *
 * Registers and resolves the `{clicutcl_*}` merge tags surfaced in GF
 * notification and confirmation builders. Reads values from entry meta written
 * by `Gravity_Forms_Adapter::on_submission()`.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gf_Merge_Tags
 */
class Gf_Merge_Tags {

	/**
	 * Standard merge tags and their corresponding entry meta keys.
	 *
	 * @var array<string,string>
	 */
	private const MERGE_TAGS = array(
		'clicutcl_channel'      => 'ct_ft_channel',
		'clicutcl_referrer'     => 'ct_ft_referrer',
		'clicutcl_utm_source'   => 'ct_ft_source',
		'clicutcl_utm_medium'   => 'ct_ft_medium',
		'clicutcl_utm_campaign' => 'ct_ft_campaign',
		'clicutcl_utm_term'     => 'ct_ft_term',
		'clicutcl_utm_content'  => 'ct_ft_content',
		'clicutcl_utm_id'       => 'ct_ft_utm_id',
	);

	/**
	 * Entry meta keys checked in order to resolve `{clicutcl_click_id}`.
	 *
	 * @var string[]
	 */
	private const CLICK_ID_META_KEYS = array(
		'ct_gclid',
		'ct_msclkid',
		'ct_ttclid',
		'ct_li_fat_id',
		'ct_rdt_cid',
		'ct_pin_cid',
		'ct_snap_cid',
		'ct_mc_cid',
	);

	/**
	 * Per-form gate, used to decide whether to expose / replace tags.
	 *
	 * @var Gf_Form_Settings_Tab
	 */
	private $settings_tab;

	/**
	 * Constructor — self-registers the GF merge-tag filters.
	 *
	 * @param Gf_Form_Settings_Tab $settings_tab Per-form gate.
	 */
	public function __construct( Gf_Form_Settings_Tab $settings_tab ) {
		$this->settings_tab = $settings_tab;

		add_filter( 'gform_custom_merge_tags', array( $this, 'register' ), 10, 4 );
		add_filter( 'gform_replace_merge_tags', array( $this, 'replace' ), 10, 7 );
	}

	/**
	 * Register ClickTrail merge tags in the GF merge-tag picker.
	 *
	 * @param array  $merge_tags Existing merge tag definitions.
	 * @param int    $form_id    Form ID.
	 * @param array  $fields     Form fields (unused).
	 * @param string $element_id Element ID context (unused).
	 * @return array
	 */
	public function register( $merge_tags, $form_id, $fields, $element_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $merge_tags ) ) {
			$merge_tags = array();
		}

		// Fail-open when form context is unavailable: continue with default behavior rather than break the integration.
		if ( 0 < absint( $form_id ) && ! $this->settings_tab->is_enabled( absint( $form_id ) ) ) {
			return $merge_tags;
		}

		foreach ( array_keys( self::MERGE_TAGS ) as $tag ) {
			$merge_tags[] = array(
				'label' => 'ClickTrail: ' . ucwords( str_replace( array( 'clicutcl_', '_' ), array( '', ' ' ), $tag ) ),
				'tag'   => '{' . $tag . '}',
			);
		}

		$merge_tags[] = array(
			'label' => 'ClickTrail: Click Id',
			'tag'   => '{clicutcl_click_id}',
		);

		return $merge_tags;
	}

	/**
	 * Replace ClickTrail merge tags in notification and confirmation text.
	 *
	 * @param string $text       Text containing merge tags.
	 * @param array  $form       Form object.
	 * @param array  $entry      Entry object.
	 * @param bool   $url_encode Whether to URL-encode values.
	 * @param bool   $esc_html   Whether to HTML-escape values.
	 * @param bool   $nl2br      Whether to convert newlines to br tags.
	 * @param string $format     Output format context.
	 * @return string
	 */
	public function replace( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
		if ( ! is_string( $text ) || false === strpos( $text, '{clicutcl_' ) ) {
			return $text;
		}

		$form_id = is_array( $form ) && ! empty( $form['id'] ) ? absint( $form['id'] ) : 0;
		// Fail-open when form context is unavailable: continue with default behavior rather than break the integration.
		if ( 0 !== $form_id && ! $this->settings_tab->is_enabled( $form_id, is_array( $form ) ? $form : null ) ) {
			return $text;
		}

		if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
			return $text;
		}

		$entry_id = absint( $entry['id'] );

		foreach ( self::MERGE_TAGS as $tag => $meta_key ) {
			$placeholder = '{' . $tag . '}';
			if ( false === strpos( $text, $placeholder ) ) {
				continue;
			}

			$raw   = (string) \gform_get_meta( $entry_id, $meta_key );
			$value = (string) apply_filters( 'clicutcl_gf_merge_tag_value', $raw, $tag, $entry, $form );

			if ( '' === $value ) {
				$value = (string) apply_filters( 'clicutcl_gf_merge_tag_default_value', '', $tag );
			}

			$formatted = $this->format_value( $value, $url_encode, $nl2br );
			$formatted = (string) apply_filters( 'clicutcl_gf_merge_tag_formatted_value', $formatted, $tag, $entry, $form, $url_encode, $esc_html, $format );
			$text      = str_replace( $placeholder, $formatted, $text );
		}

		// `{clicutcl_click_id}`: first non-empty click ID stored in entry meta.
		if ( false !== strpos( $text, '{clicutcl_click_id}' ) ) {
			$click_value = '';
			foreach ( self::CLICK_ID_META_KEYS as $click_meta ) {
				$v = (string) \gform_get_meta( $entry_id, $click_meta );
				if ( '' !== $v ) {
					$click_value = $v;
					break;
				}
			}

			$click_value = (string) apply_filters( 'clicutcl_gf_merge_tag_value', $click_value, 'clicutcl_click_id', $entry, $form );
			if ( '' === $click_value ) {
				$click_value = (string) apply_filters( 'clicutcl_gf_merge_tag_default_value', '', 'clicutcl_click_id' );
			}

			$click_formatted = $this->format_value( $click_value, $url_encode, $nl2br );
			$click_formatted = (string) apply_filters( 'clicutcl_gf_merge_tag_formatted_value', $click_formatted, 'clicutcl_click_id', $entry, $form, $url_encode, $esc_html, $format );
			$text            = str_replace( '{clicutcl_click_id}', $click_formatted, $text );
		}

		return $text;
	}

	/**
	 * Apply GF merge tag formatting conventions to a value.
	 *
	 * @param string $value      Raw value.
	 * @param bool   $url_encode URL-encode the value.
	 * @param bool   $nl2br      Convert newlines to HTML line breaks.
	 * @return string
	 */
	private function format_value( $value, $url_encode, $nl2br ) {
		if ( '' === $value ) {
			return '';
		}
		// ClickTrail merge-tag values are untrusted visitor input (UTMs, referrer, click
		// IDs). GF passes $esc_html = false for some output contexts (e.g. HTML email
		// bodies), so we escape unconditionally here rather than trust that flag. URL-encoded
		// output is already safe in both URL and HTML contexts, so it skips esc_html.
		if ( $url_encode ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- GF merge tag convention requires urlencode.
			$value = urlencode( $value );
		} else {
			$value = esc_html( $value );
		}
		if ( $nl2br ) {
			$value = nl2br( $value );
		}
		return $value;
	}
}
