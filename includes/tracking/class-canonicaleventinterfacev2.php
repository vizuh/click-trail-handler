<?php
/**
 * Canonical Event Interface v2
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CanonicalEventInterfaceV2
 */
interface CanonicalEventInterfaceV2 {
	/**
	 * Return normalized event payload.
	 *
	 * @return array
	 */
	public function to_array(): array;

	/**
	 * Normalize raw payload to canonical schema.
	 *
	 * @param array $payload Raw payload.
	 * @return array
	 */
	public static function normalize( array $payload ): array;

	/**
	 * Validate normalized canonical payload.
	 *
	 * @param array $payload Canonical payload.
	 * @return bool
	 */
	public static function validate( array $payload ): bool;
}

