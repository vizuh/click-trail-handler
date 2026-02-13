<?php
/**
 * Consent Helper
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Consent
 */
class Consent {
	/**
	 * Get consent state from cookie.
	 *
	 * @return array
	 */
	public static function get_state() {
		if ( empty( $_COOKIE['ct_consent'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decode below.
		$raw = wp_unslash( $_COOKIE['ct_consent'] );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		return array(
			'marketing' => ! empty( $data['marketing'] ),
			'analytics' => ! empty( $data['analytics'] ),
		);
	}

	/**
	 * Check if marketing consent is granted.
	 *
	 * @return bool
	 */
	public static function marketing_allowed() {
		$state = self::get_state();
		return ! empty( $state['marketing'] );
	}
}
