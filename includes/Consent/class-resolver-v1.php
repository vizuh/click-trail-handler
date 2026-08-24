<?php
/**
 * Pure consent decision resolver.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Resolver_V1
 */
final class Resolver_V1 {
	private const SOURCES = array(
		'administrative_override' => Decision_V1::BASIS_ADMIN_OVERRIDE,
		'live_cmp'                => Decision_V1::BASIS_CMP,
		'plugin_banner'           => Decision_V1::BASIS_PLUGIN_BANNER,
		'bridge_mirror'           => Decision_V1::BASIS_BRIDGE_MIRROR,
	);

	/**
	 * Resolve supplied evidence to one versioned decision.
	 *
	 * @param array $input Versioned consent evidence.
	 * @return Decision_V1
	 */
	public static function resolve( array $input ): Decision_V1 {
		$now = isset( $input['now'] ) ? (int) $input['now'] : time();
		if ( isset( $input['required'] ) && ! $input['required'] ) {
			return new Decision_V1( Decision_V1::DECISION_UNRESOLVED, Decision_V1::BASIS_NOT_REQUIRED, 0, $now, null, 'policy' );
		}

		$selected = null;
		foreach ( self::SOURCES as $key => $basis ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$candidate = self::from_record( $input[ $key ], $basis, $key, $now );
			if ( null === $selected ) {
				$selected = $candidate;
				if ( Decision_V1::BASIS_ADMIN_OVERRIDE === $basis || in_array( $candidate->decision(), array( Decision_V1::DECISION_INVALID, Decision_V1::DECISION_UNRESOLVED ), true ) ) {
					return $candidate;
				}
				continue;
			}

			if ( Decision_V1::DECISION_GRANTED === $selected->decision() && Decision_V1::DECISION_DENIED === $candidate->decision() && $candidate->revision() >= $selected->revision() ) {
				return $candidate;
			}
			if ( $candidate->revision() === $selected->revision() && $candidate->decision() !== $selected->decision() ) {
				return new Decision_V1( Decision_V1::DECISION_DENIED, $candidate->basis(), $candidate->revision(), $now, null, 'revision_conflict' );
			}
		}

		if ( $selected ) {
			return $selected;
		}
		if ( ! empty( $input['timeout'] ) ) {
			return new Decision_V1( Decision_V1::DECISION_UNRESOLVED, Decision_V1::BASIS_FALLBACK_TIMEOUT, 0, $now, null, 'timeout' );
		}

		return new Decision_V1( Decision_V1::DECISION_UNRESOLVED, Decision_V1::BASIS_NONE, 0, $now );
	}

	/**
	 * Normalize one evidence record.
	 *
	 * @param mixed  $record    Evidence record.
	 * @param string $basis     Forced policy basis.
	 * @param string $source_id Source identifier.
	 * @param int    $now       Resolution timestamp.
	 * @return Decision_V1
	 */
	private static function from_record( mixed $record, string $basis, string $source_id, int $now ): Decision_V1 {
		if ( ! is_array( $record ) || ! isset( $record['decision'] ) ) {
			return new Decision_V1( Decision_V1::DECISION_INVALID, $basis, 0, $now, null, $source_id );
		}

		$decision    = (string) $record['decision'];
		$revision    = isset( $record['revision'] ) ? (int) $record['revision'] : 0;
		$captured_at = isset( $record['captured_at'] ) ? (int) $record['captured_at'] : null;
		$expires_at  = isset( $record['expires_at'] ) ? (int) $record['expires_at'] : null;
		if ( Decision_V1::BASIS_BRIDGE_MIRROR === $basis && null === $expires_at ) {
			return new Decision_V1( Decision_V1::DECISION_INVALID, $basis, $revision, $captured_at, null, $source_id );
		}
		if ( null !== $expires_at && $expires_at <= $now ) {
			return new Decision_V1( Decision_V1::DECISION_UNRESOLVED, $basis, $revision, $captured_at, $expires_at, $source_id );
		}

		try {
			return new Decision_V1( $decision, $basis, $revision, $captured_at, $expires_at, $source_id );
		} catch ( \InvalidArgumentException $exception ) {
			return new Decision_V1( Decision_V1::DECISION_INVALID, $basis, max( 0, $revision ), $captured_at, $expires_at, $source_id );
		}
	}
}
