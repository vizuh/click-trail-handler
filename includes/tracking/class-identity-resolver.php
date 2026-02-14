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

		$phone = isset( $input['phone'] ) ? preg_replace( '/[^0-9+]/', '', (string) $input['phone'] ) : '';
		if ( in_array( 'hashed_phone', $allowed_fields, true ) && $phone && preg_match( '/^\+?[0-9]{7,20}$/', $phone ) ) {
			$out['hashed_phone'] = hash( 'sha256', $phone );
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
}

