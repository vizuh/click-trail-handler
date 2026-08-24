<?php
/**
 * Versioned consent snapshot helper.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Snapshot_V1
 */
final class Snapshot_V1 {
	/**
	 * Capture current legacy category state as a truthful v1 snapshot.
	 *
	 * @param array $state    Legacy marketing/analytics state.
	 * @param bool  $required Whether consent is required by current policy.
	 * @param int   $now      Capture timestamp.
	 * @return array
	 */
	public static function capture( array $state, bool $required, ?int $now = null ): array {
		$now = $now ?? time();
		if ( ! $required ) {
			$decision = Resolver_V1::resolve(
				array(
					'required' => false,
					'now'      => $now,
				)
			);
		} elseif ( array_key_exists( 'marketing', $state ) ) {
			$decision = new Decision_V1(
				! empty( $state['marketing'] ) ? Decision_V1::DECISION_GRANTED : Decision_V1::DECISION_DENIED,
				Decision_V1::BASIS_LEGACY_UNVERSIONED,
				0,
				$now,
				null,
				'legacy_cookie'
			);
		} else {
			$decision = Resolver_V1::resolve(
				array(
					'required' => true,
					'now'      => $now,
				)
			);
		}

		return array_merge(
			$decision->to_array(),
			array(
				'marketing' => $decision->allows_processing(),
				'analytics' => $decision->allows_processing() && ( ! $required || ! empty( $state['analytics'] ) ),
			)
		);
	}

	/**
	 * Normalize a v1 or historical boolean snapshot.
	 *
	 * @param mixed $snapshot JSON string or decoded snapshot.
	 * @return array Empty when invalid.
	 */
	public static function normalize( mixed $snapshot ): array {
		if ( is_string( $snapshot ) ) {
			$snapshot = json_decode( $snapshot, true );
		}
		if ( ! is_array( $snapshot ) ) {
			return array();
		}

		if ( Decision_V1::SCHEMA_VERSION === ( $snapshot['schema_version'] ?? null ) ) {
			try {
				$decision = Decision_V1::from_array( $snapshot );
			} catch ( \InvalidArgumentException $exception ) {
				return array();
			}

			return array_merge(
				$decision->to_array(),
				array(
					'marketing' => $decision->allows_processing(),
					'analytics' => $decision->allows_processing() && ! empty( $snapshot['analytics'] ),
				)
			);
		}

		if ( ! array_key_exists( 'marketing', $snapshot ) ) {
			return array();
		}

		return self::capture( $snapshot, true );
	}
}
