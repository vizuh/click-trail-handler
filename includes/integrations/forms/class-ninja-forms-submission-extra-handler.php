<?php
/**
 * Ninja Forms submission extra handler.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render ClickTrail attribution stored in Ninja Forms submission extras.
 */
class Ninja_Forms_Submission_Extra_Handler {

	/**
	 * Top-level Ninja Forms extra-data key used by ClickTrail.
	 */
	public const EXTRA_VALUE_KEY = 'clicktrail_attribution';

	/**
	 * Human-friendly labels for common attribution keys.
	 *
	 * @var array<string,string>
	 */
	private const FIELD_LABELS = array(
		'ft_source'          => 'First source',
		'ft_medium'          => 'First medium',
		'ft_campaign'        => 'First campaign',
		'ft_term'            => 'First term',
		'ft_content'         => 'First content',
		'ft_utm_id'          => 'First UTM ID',
		'ft_landing_page'    => 'First landing page',
		'ft_referrer'        => 'First referrer',
		'ft_touch_timestamp' => 'First touch timestamp',
		'lt_source'          => 'Last source',
		'lt_medium'          => 'Last medium',
		'lt_campaign'        => 'Last campaign',
		'lt_term'            => 'Last term',
		'lt_content'         => 'Last content',
		'lt_utm_id'          => 'Last UTM ID',
		'lt_landing_page'    => 'Last landing page',
		'lt_referrer'        => 'Last referrer',
		'lt_touch_timestamp' => 'Last touch timestamp',
		'gclid'              => 'GCLID',
		'fbclid'             => 'FBCLID',
		'ttclid'             => 'TTCLID',
		'msclkid'            => 'MSCLKID',
		'twclid'             => 'TWCLID',
		'li_fat_id'          => 'LinkedIn click ID',
		'sccid'              => 'Snapchat click ID',
		'epik'               => 'Pinterest click ID',
		'fbc'                => 'FBC',
		'fbp'                => 'FBP',
		'ttp'                => 'TikTok browser ID',
		'li_gc'              => 'LinkedIn browser ID',
		'ga_client_id'       => 'GA client ID',
		'ga_session_id'      => 'GA session ID',
		'ga_session_number'  => 'GA session number',
		'session_count'      => 'Session count',
	);

	/**
	 * Render the Ninja Forms submission metabox.
	 *
	 * @param mixed $extra_value Stored extra value.
	 * @param mixed $nf_sub      Ninja Forms submission object.
	 * @return object|null
	 */
	public function handle( $extra_value, $nf_sub = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! class_exists( '\NinjaForms\Includes\Entities\MetaboxOutputEntity' ) ) {
			return null;
		}

		$rows = $this->build_rows( $extra_value );
		if ( empty( $rows ) ) {
			return null;
		}

		$html = '<table class="widefat striped"><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			$html .= '<th scope="row">' . esc_html( $row['label'] ) . '</th>';
			$html .= '<td>' . esc_html( $row['value'] ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		return \NinjaForms\Includes\Entities\MetaboxOutputEntity::fromArray(
			array(
				'title' => esc_html__( 'ClickTrail Attribution', 'click-trail-handler' ),
				'items' => array(
					array(
						'type' => 'html',
						'html' => $html,
					),
				),
			)
		);
	}

	/**
	 * Build display rows from the stored extra payload.
	 *
	 * @param mixed $extra_value Stored value.
	 * @return array<int,array{label:string,value:string}>
	 */
	private function build_rows( $extra_value ) {
		$payload = $this->normalize_payload( $extra_value );
		if ( empty( $payload ) ) {
			return array();
		}

		$rows = array();
		foreach ( $payload as $key => $value ) {
			$rows[] = array(
				'label' => $this->humanize_key( $key ),
				'value' => $value,
			);
		}

		return $rows;
	}

	/**
	 * Normalize stored attribution extras into a canonical scalar array.
	 *
	 * @param mixed $payload Raw stored extra value.
	 * @return array<string,string>
	 */
	private function normalize_payload( $payload ) {
		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		if ( ! is_array( $payload ) ) {
			return array();
		}

		$payload        = Attribution_Provider::sanitize( $payload );
		$allowed_keys   = array_fill_keys( Attribution_Provider::get_field_mapping(), true );
		$legacy_aliases = Attribution_Provider::get_field_alias_mapping();
		$normalized     = array();

		foreach ( $payload as $key => $value ) {
			if ( isset( $legacy_aliases[ $key ] ) && $legacy_aliases[ $key ] !== $key ) {
				continue;
			}

			if ( ! isset( $allowed_keys[ $key ] ) ) {
				continue;
			}

			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				continue;
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * Humanize an attribution key for metabox display.
	 *
	 * @param string $key Attribution key.
	 * @return string
	 */
	private function humanize_key( $key ) {
		if ( isset( self::FIELD_LABELS[ $key ] ) ) {
			return self::FIELD_LABELS[ $key ];
		}

		$parts = explode( '_', $key );
		$parts = array_map(
			static function( $part ) {
				$acronyms = array( 'utm', 'ga', 'id', 'tt', 'fb', 'li', 'gc' );
				if ( in_array( $part, $acronyms, true ) ) {
					return strtoupper( $part );
				}

				return ucfirst( $part );
			},
			$parts
		);

		return implode( ' ', $parts );
	}
}
