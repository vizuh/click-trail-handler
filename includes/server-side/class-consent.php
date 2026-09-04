<?php
/**
 * Consent Helper
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

use CLICUTCL\Consent\Snapshot_V1;
use CLICUTCL\Settings\Attribution_Settings;

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
		$cookie_name = 'ct_consent';
		if ( class_exists( 'CLICUTCL\\Modules\\Consent_Mode\\Consent_Mode_Settings' ) ) {
			$settings    = new \CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings();
			$cookie_name = $settings->get_cookie_name();
		}

		if ( empty( $_COOKIE[ $cookie_name ] ) && ! empty( $_COOKIE['ct_consent_state'] ) ) {
			$cookie_name = 'ct_consent_state';
		}

		if ( empty( $_COOKIE[ $cookie_name ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decode below.
		$raw  = wp_unslash( $_COOKIE[ $cookie_name ] );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$token = strtolower( trim( (string) $raw ) );
			if ( in_array( $token, array( 'granted', '1', 'true', 'yes' ), true ) ) {
				$data = array(
					'marketing' => true,
					'analytics' => true,
				);
			} elseif ( in_array( $token, array( 'denied', '0', 'false', 'no' ), true ) ) {
				$data = array(
					'marketing' => false,
					'analytics' => false,
				);
			}
		}

		if ( ! is_array( $data ) && 'ct_consent_state' !== $cookie_name && ! empty( $_COOKIE['ct_consent_state'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized bridge JSON decoded below.
			$data = json_decode( wp_unslash( $_COOKIE['ct_consent_state'] ), true );
		}

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
		return ! empty( self::snapshot()['marketing'] );
	}

	/**
	 * Return the current policy requirement.
	 *
	 * @return bool
	 */
	public static function is_required(): bool {
		if ( class_exists( 'CLICUTCL\\Modules\\Consent_Mode\\Consent_Mode_Settings' ) ) {
			$settings = new \CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings();
			if ( method_exists( $settings, 'is_consent_mode_enabled' ) ) {
				return $settings->is_consent_mode_enabled() && $settings->is_consent_required_for_request();
			}
		}

		$options = class_exists( Attribution_Settings::class ) ? Attribution_Settings::get_all() : array();
		return ! empty( $options['require_consent'] );
	}

	/**
	 * Queue workers have no visitor geography: geo mode must fail closed.
	 *
	 * @return bool
	 */
	public static function is_required_for_queue(): bool {
		if ( class_exists( 'CLICUTCL\\Modules\\Consent_Mode\\Consent_Mode_Settings' ) ) {
			$settings = new \CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings();
			return $settings->is_consent_mode_enabled() && 'relaxed' !== $settings->get_mode();
		}

		$options = class_exists( Attribution_Settings::class ) ? Attribution_Settings::get_all() : array();
		return ! empty( $options['require_consent'] );
	}

	/**
	 * Return the current versioned consent snapshot.
	 *
	 * @return array
	 */
	public static function snapshot(): array {
		return Snapshot_V1::capture( self::get_state(), self::is_required() );
	}
}
