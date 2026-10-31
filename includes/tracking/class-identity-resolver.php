<?php
/**
 * Identity resolver (consent-gated minimal by default).
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Identity_Resolver
 */
class Identity_Resolver implements IdentityResolverInterface {
	/**
	 * Per-request count of identity values dropped during normalisation (#144),
	 * keyed `phone_no_country` / `phone_invalid`. Not a delivery failure, so it
	 * stays out of Dispatcher failure telemetry; surfaced per event in the v2
	 * intake debug buffer.
	 *
	 * @var array<string, int>
	 */
	private static $drop_counts = array();

	/**
	 * Return per-request identity drop counts.
	 *
	 * @return array<string, int>
	 */
	public static function get_drop_counts(): array {
		return self::$drop_counts;
	}

	/**
	 * Reset per-request identity drop counts.
	 *
	 * @return void
	 */
	public static function reset_drop_counts(): void {
		self::$drop_counts = array();
	}

	/**
	 * Resolve sanitized identity payload for destinations.
	 *
	 * @param array $input   Raw input.
	 * @param array $context Context.
	 * @return array
	 */
	public function resolve( array $input, array $context = array() ): array {
		$settings = Settings::get();
		$mode     = isset( $settings['identity_policy']['mode'] ) ? sanitize_key( (string) $settings['identity_policy']['mode'] ) : 'consent_gated_minimal';

		$consent = new Consent_Decision();
		if ( 'consent_gated_minimal' === $mode && ! $consent->marketing_allowed( $context ) ) {
			return array();
		}

		$allowed_fields = $consent->allowed_identity_fields( $context );
		$out            = array();

		$email = isset( $input['email'] ) ? strtolower( trim( (string) $input['email'] ) ) : '';
		if ( in_array( 'hashed_email', $allowed_fields, true ) && $email && is_email( $email ) ) {
			$out['hashed_email'] = hash( 'sha256', $email );
		}

		if ( in_array( 'hashed_phone', $allowed_fields, true ) && isset( $input['phone'] ) && '' !== trim( (string) $input['phone'] ) ) {
			$phone = $this->normalize_phone( (string) $input['phone'], $input, $context );
			if ( '' !== $phone ) {
				$out['hashed_phone'] = hash( 'sha256', $phone );
			}
		}

		// Optional non-PII transport context, destination-specific and filterable.
		if ( ! empty( $context['include_ip_ua'] ) ) {
			$ip = isset( $input['ip'] ) ? (string) $input['ip'] : '';
			if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$out['ip_address'] = $ip;
			}

			$ua = isset( $input['user_agent'] ) ? sanitize_text_field( (string) $input['user_agent'] ) : '';
			if ( $ua ) {
				$out['user_agent'] = substr( $ua, 0, 512 );
			}
		}

		return $out;
	}

	/**
	 * Normalise a phone number to E.164 (`+` + 7–15 digits) or return '' (#144).
	 *
	 * Google Enhanced Conversions and Meta CAPI hash E.164, so a national number
	 * or a `00`-prefixed one hashed as-is matches nothing. Everything but digits
	 * and a leading `+` is stripped, `00` becomes `+`, and a number without `+`
	 * is prefixed with the default country's calling code; when no country can
	 * be resolved the number is dropped rather than hashed in an unmatched shape.
	 *
	 * @param string $raw     Raw phone input.
	 * @param array  $input   Full identity input (may carry `country`).
	 * @param array  $context Resolver context.
	 * @return string E.164 number or ''.
	 */
	private function normalize_phone( string $raw, array $input, array $context ): string {
		$phone = preg_replace( '/[^0-9+]/', '', $raw );
		$plus  = 0 === strpos( $phone, '+' );
		$phone = str_replace( '+', '', $phone );

		if ( ! $plus && 0 === strpos( $phone, '00' ) ) {
			$phone = substr( $phone, 2 );
			$plus  = true;
		}

		if ( ! $plus ) {
			$calling_code = Phone_Calling_Codes::for_country( $this->default_country( $input, $context ) );
			if ( '' === $calling_code ) {
				self::record_drop( 'phone_no_country' );
				return '';
			}
			$phone = $calling_code . $phone;
		}

		$phone = '+' . $phone;
		if ( ! preg_match( '/^\+[1-9][0-9]{6,14}$/', $phone ) ) {
			self::record_drop( 'phone_invalid' );
			return '';
		}

		return $phone;
	}

	/**
	 * Count a dropped identity value for this request.
	 *
	 * @param string $reason Drop reason key.
	 * @return void
	 */
	private static function record_drop( string $reason ): void {
		self::$drop_counts[ $reason ] = ( self::$drop_counts[ $reason ] ?? 0 ) + 1;
	}

	/**
	 * Resolve the ISO 3166-1 alpha-2 country used to complete national phone numbers.
	 *
	 * Order: `clicutcl_identity_default_country` filter, WooCommerce base country,
	 * the billing/order country on the event (`$input['country']`), else ''.
	 *
	 * @param array $input   Identity input.
	 * @param array $context Resolver context.
	 * @return string Alpha-2 code or ''.
	 */
	private function default_country( array $input, array $context ): string {
		/**
		 * Filter the default country for phone numbers posted without a country code.
		 *
		 * @param string $country ISO 3166-1 alpha-2 code; '' lets WooCommerce / the event decide.
		 * @param array  $input   Identity input being resolved.
		 * @param array  $context Resolver context.
		 */
		$country = (string) apply_filters( 'clicutcl_identity_default_country', '', $input, $context );
		if ( '' !== $country ) {
			return $country;
		}

		if ( function_exists( 'WC' ) && isset( WC()->countries ) && method_exists( WC()->countries, 'get_base_country' ) ) {
			$country = (string) WC()->countries->get_base_country();
			if ( '' !== $country ) {
				return $country;
			}
		}

		return isset( $input['country'] ) && is_scalar( $input['country'] ) ? (string) $input['country'] : '';
	}
}
