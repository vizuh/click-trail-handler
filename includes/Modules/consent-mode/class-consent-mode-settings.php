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
	 * Valid consent behavior modes.
	 * Keyed by value for O(1) isset() validation — single source of truth
	 * shared with Admin and Plugin bootstrap.
	 *
	 * @var array<string,true>
	 */
	public const ALLOWED_MODES = array(
		'strict'  => true,
		'relaxed' => true,
		'geo'     => true,
	);

	/**
	 * Valid CMP (Consent Management Platform) source identifiers.
	 * Keyed by value for O(1) isset() validation — single source of truth
	 * shared with Admin and Plugin bootstrap.
	 *
	 * @var array<string,true>
	 */
	public const ALLOWED_CMP_SOURCES = array(
		'auto'      => true,
		'plugin'    => true,
		'cookiebot' => true,
		'onetrust'  => true,
		'complianz' => true,
		'gtm'       => true,
		'custom'    => true,
	);

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
			'enabled'           => false,
			'mode'              => 'strict',
			'regions'           => Regions::get_regions(),
			'cmp_source'        => 'auto',
			'cmp_timeout_ms'    => 3000,
			'cookie_name'       => 'ct_consent',
			'gcm_analytics_key' => 'analytics_storage',
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

			$new_value['enabled'] = ! empty( $value['enabled'] );
			$mode                 = isset( $value['mode'] ) ? sanitize_key( (string) $value['mode'] ) : 'strict';
			$new_value['mode']    = isset( self::ALLOWED_MODES[ $mode ] ) ? $mode : 'strict';

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

			$cmp_source = isset( $value['cmp_source'] ) ? sanitize_key( (string) $value['cmp_source'] ) : 'auto';
			$new_value['cmp_source'] = isset( self::ALLOWED_CMP_SOURCES[ $cmp_source ] ) ? $cmp_source : 'auto';

			$cmp_timeout_ms = isset( $value['cmp_timeout_ms'] ) ? absint( $value['cmp_timeout_ms'] ) : 3000;
			$new_value['cmp_timeout_ms'] = min( 10000, max( 500, $cmp_timeout_ms ) );

			$cookie_name = isset( $value['cookie_name'] ) ? sanitize_key( (string) $value['cookie_name'] ) : 'ct_consent';
			$new_value['cookie_name'] = '' !== $cookie_name ? $cookie_name : 'ct_consent';

			$gcm_analytics_key = isset( $value['gcm_analytics_key'] ) ? sanitize_key( (string) $value['gcm_analytics_key'] ) : 'analytics_storage';
			$new_value['gcm_analytics_key'] = '' !== $gcm_analytics_key ? $gcm_analytics_key : 'analytics_storage';

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
	 * Accessor for consent behavior mode.
	 *
	 * @return string strict|relaxed|geo
	 */
	public function get_mode() {
		$settings = $this->get();
		$mode = isset( $settings['mode'] ) ? sanitize_key( (string) $settings['mode'] ) : 'strict';
		return isset( self::ALLOWED_MODES[ $mode ] ) ? $mode : 'strict';
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

	/**
	 * Whether runtime attribution/event collection should require consent now.
	 *
	 * strict  => always required.
	 * relaxed => never required.
	 * geo     => required only when request region matches configured regions.
	 *
	 * @return bool
	 */
	public function is_consent_required_for_request() {
		$mode = $this->get_mode();
		if ( 'relaxed' === $mode ) {
			return false;
		}

		if ( 'geo' === $mode ) {
			$regions = $this->get_regions();
			if ( empty( $regions ) ) {
				return true;
			}
			return $this->request_matches_regions( $regions );
		}

		return true;
	}

	/**
	 * Accessor for configured CMP source.
	 *
	 * @return string
	 */
	public function get_cmp_source() {
		$settings = $this->get();
		return isset( $settings['cmp_source'] ) ? sanitize_key( (string) $settings['cmp_source'] ) : 'auto';
	}

	/**
	 * Accessor for CMP wait timeout in milliseconds.
	 *
	 * @return int
	 */
	public function get_cmp_timeout_ms() {
		$settings = $this->get();
		$timeout  = isset( $settings['cmp_timeout_ms'] ) ? absint( $settings['cmp_timeout_ms'] ) : 3000;
		return min( 10000, max( 500, $timeout ) );
	}

	/**
	 * Accessor for consent cookie name.
	 *
	 * @return string
	 */
	public function get_cookie_name() {
		$settings = $this->get();
		$name     = isset( $settings['cookie_name'] ) ? sanitize_key( (string) $settings['cookie_name'] ) : 'ct_consent';
		return '' !== $name ? $name : 'ct_consent';
	}

	/**
	 * Accessor for GCM analytics key mapping.
	 *
	 * @return string
	 */
	public function get_gcm_analytics_key() {
		$settings = $this->get();
		$key      = isset( $settings['gcm_analytics_key'] ) ? sanitize_key( (string) $settings['gcm_analytics_key'] ) : 'analytics_storage';
		return '' !== $key ? $key : 'analytics_storage';
	}

	/**
	 * Resolve best-effort country code from request/proxy headers.
	 *
	 * @return string
	 */
	private function get_request_country_code() {
		$candidates = array(
			'HTTP_CF_IPCOUNTRY',
			'HTTP_X_COUNTRY_CODE',
			'HTTP_GEOIP_COUNTRY_CODE',
			'GEOIP_COUNTRY_CODE',
		);

		foreach ( $candidates as $key ) {
			$value = strtoupper( trim( $this->get_server_value( $key ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Resolve US state token (US-CA, US-NY...) when available.
	 *
	 * @return string
	 */
	private function get_request_us_state_token() {
		if ( 'US' !== $this->get_request_country_code() ) {
			return '';
		}

		$candidates = array(
			'HTTP_CF_REGION_CODE',
			'HTTP_X_REGION_CODE',
			'GEOIP_REGION',
		);
		foreach ( $candidates as $key ) {
			$value = strtoupper( trim( $this->get_server_value( $key ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $value ) ) {
				return 'US-' . $value;
			}
		}

		return '';
	}

	/**
	 * Check whether the current request matches configured geo tokens.
	 *
	 * @param array $regions Region tokens.
	 * @return bool
	 */
	private function request_matches_regions( array $regions ) {
		$country = $this->get_request_country_code();
		if ( '' === $country ) {
			// Fail-safe: unknown region defaults to requiring consent.
			return true;
		}

		$us_state = $this->get_request_us_state_token();
		$eea      = array_values( array_unique( array_map( 'strtoupper', Regions::get_regions() ) ) );

		foreach ( $regions as $region ) {
			$token = strtoupper( trim( (string) $region ) );
			if ( '' === $token ) {
				continue;
			}
			if ( 'EU' === $token ) {
				$token = 'EEA';
			} elseif ( 'GB' === $token ) {
				$token = 'UK';
			}

			if ( 'EEA' === $token && in_array( $country, $eea, true ) ) {
				return true;
			}
			if ( 'UK' === $token && in_array( $country, array( 'UK', 'GB' ), true ) ) {
				return true;
			}
			if ( 'US' === $token && 'US' === $country ) {
				return true;
			}
			if ( $token === $country ) {
				return true;
			}
			if ( '' !== $us_state && $token === $us_state ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Safe server var accessor with filter_input fallback.
	 *
	 * @param string $key Header/server key.
	 * @return string
	 */
	private function get_server_value( $key ) {
		$key   = strtoupper( sanitize_key( (string) $key ) );
		$value = filter_input( INPUT_SERVER, $key, FILTER_UNSAFE_RAW );
		if ( null === $value || false === $value ) {
			$value = isset( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_text_field() below.
		}

		return sanitize_text_field( (string) $value );
	}
}
