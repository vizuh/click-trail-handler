<?php
/**
 * Identity Resolver Interface
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface IdentityResolverInterface
 */
interface IdentityResolverInterface {
	/**
	 * Resolve and sanitize identity payload for destinations.
	 *
	 * @param array $input   Raw identity input.
	 * @param array $context Context (consent/region/destination).
	 * @return array
	 */
	public function resolve( array $input, array $context = array() ): array;
}

