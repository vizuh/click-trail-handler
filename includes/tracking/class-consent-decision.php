<?php
/**
 * Consent decision helper.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

use CLICUTCL\Server_Side\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Consent_Decision
 */
class Consent_Decision implements ConsentDecisionInterface {
	/**
	 * Check whether marketing processing is allowed.
	 *
	 * @param array $context Optional context.
	 * @return bool
	 */
	public function marketing_allowed( array $context = array() ): bool {
		// Allow explicit override in context for trusted server callers.
		if ( array_key_exists( 'marketing_allowed', $context ) ) {
			return ! empty( $context['marketing_allowed'] );
		}

		return Consent::marketing_allowed();
	}

	/**
	 * Return allowed identity fields.
	 *
	 * @param array $context Optional context.
	 * @return array
	 */
	public function allowed_identity_fields( array $context = array() ): array {
		if ( ! $this->marketing_allowed( $context ) ) {
			return array();
		}

		$allowed = array( 'hashed_email', 'hashed_phone' );
		return apply_filters( 'clicutcl_identity_fields_allowed', $allowed, $context );
	}
}

