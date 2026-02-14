<?php
/**
 * Class ClickTrail\Modules\Consent_Mode\Consent_Mode_Settings
 *
 * @package   ClickTrail
 */

namespace CLICUTCL\Modules\Consent_Mode;

use CLICUTCL\Core\Storage\Setting;

/**
 * Class to store user consent mode settings.
 */
class Consent_Mode_Settings extends Setting {

	/**
	 * The user option name for this setting.
	 */
	const OPTION = 'clicutcl_consent_mode';

	/**
	 * Gets the expected value type.
	 *
	 * @return string The type name.
	 */
	protected function get_type() {
		return 'object';
	}

	/**
	 * Gets the default value.
	 *
	 * @return array The default value.
	 */
	protected function get_default() {
		return array(
			'enabled' => false,
			'regions' => Regions::get_regions(),
		);
	}

	/**
	 * Gets the callback for sanitizing the setting's value before saving.
	 *
	 * @return callable Sanitize callback.
	 */
	protected function get_sanitize_callback() {
		return function ( $value ) {
			$new_value = $this->get();
			$value     = is_array( $value ) ? wp_unslash( $value ) : array();

			if ( isset( $value['enabled'] ) ) {
				$new_value['enabled'] = (bool) $value['enabled'];
			}

			$raw_regions = array();
			if ( isset( $value['regions'] ) ) {
				if ( is_array( $value['regions'] ) ) {
					$raw_regions = $value['regions'];
				} else {
					$raw_regions = preg_split( '/[\s,]+/', (string) $value['regions'] );
				}
			}

			if ( ! empty( $raw_regions ) && is_array( $raw_regions ) ) {
				$region_codes = array_reduce(
					$raw_regions,
					static function ( $regions, $region_code ) {
						$region_code = strtoupper( trim( (string) $region_code ) );
						if ( '' === $region_code ) {
							return $regions;
						}

						$aliases = array(
							'EU' => 'EEA',
							'GB' => 'UK',
						);
						if ( isset( $aliases[ $region_code ] ) ) {
							$region_code = $aliases[ $region_code ];
						}

						// Accept broad region labels plus ISO country/state-like tokens.
						if ( ! preg_match( '#^(EEA|UK|US|US-[A-Z]{2}|[A-Z]{2}(-[A-Z]{2})?)$#', $region_code ) ) {
							return $regions;
						}

						// Store as keys to remove duplicates.
						$regions[ $region_code ] = true;
						return $regions;
					},
					array()
				);

				$new_value['regions'] = array_keys( $region_codes );
			}

			return $new_value;
		};
	}

	/**
	 * Accessor for the `enabled` setting.
	 *
	 * @return bool TRUE if consent mode is enabled, otherwise FALSE.
	 */
	public function is_consent_mode_enabled() {
		$settings = $this->get();
		return isset( $settings['enabled'] ) ? $settings['enabled'] : false;
	}

	/**
	 * Accessor for the `regions` setting.
	 *
	 * @return array<string> Array of ISO 3166-2 region codes.
	 */
	public function get_regions() {
		$settings = $this->get();
		return isset( $settings['regions'] ) ? $settings['regions'] : array();
	}
}
