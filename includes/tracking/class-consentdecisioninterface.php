<?php
/**
 * Consent Decision Interface
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ConsentDecisionInterface
 */
interface ConsentDecisionInterface {
	/**
	 * Check whether marketing processing is allowed.
	 *
	 * @param array $context Optional context.
	 * @return bool
	 */
	public function marketing_allowed( array $context = array() ): bool;

	/**
	 * Return allowed identity fields under current consent context.
	 *
	 * @param array $context Optional context.
	 * @return array
	 */
	public function allowed_identity_fields( array $context = array() ): array;
}

