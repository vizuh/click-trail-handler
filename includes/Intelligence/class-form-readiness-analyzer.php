<?php
/**
 * Pure form-readiness evidence comparator.
 *
 * This class compares field-presence snapshots only. It does not read form
 * plugins, WordPress state, submissions, provider records, or ClickTrail rows.
 * It never accepts or returns attribution values.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Intelligence;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compare expected, submitted, and named storage-surface evidence.
 */
class Form_Readiness_Analyzer {

	public const REQUEST_SCHEMA = 'clicktrail/form-readiness/request.v1';
	public const REPORT_SCHEMA  = 'clicktrail/form-readiness/report.v1';

	/**
	 * Adapter-to-pattern contract.
	 *
	 * @var array<string,string>
	 */
	private const ADAPTER_PATTERNS = array(
		'cf7'       => 'automatic_hidden',
		'fluent'    => 'automatic_hidden',
		'gravity'   => 'matching_fields',
		'wpforms'   => 'matching_fields',
		'elementor' => 'hook_storage',
		'ninja'     => 'hook_storage',
	);

	/**
	 * Allowed surface-observation states.
	 *
	 * @var string[]
	 */
	private const SNAPSHOT_STATES = array(
		'observed',
		'absent',
		'unavailable',
		'not_applicable',
	);

	/**
	 * Allowed consent states.
	 *
	 * @var string[]
	 */
	private const CONSENT_STATES = array(
		'granted',
		'denied',
		'unknown',
	);

	/**
	 * Allowed source-data states.
	 *
	 * @var string[]
	 */
	private const SOURCE_STATES = array(
		'present',
		'empty',
	);

	/**
	 * Allowed adapter-environment states.
	 *
	 * @var string[]
	 */
	private const ENVIRONMENT_STATES = array(
		'full',
		'lite_or_no_entry',
		'not_detected',
		'unknown',
	);

	/**
	 * Allowed provider-write results.
	 *
	 * @var string[]
	 */
	private const WRITE_RESULTS = array(
		'ok',
		'failed',
		'unchecked',
		'not_applicable',
	);

	/**
	 * Closed report reason-code vocabulary.
	 *
	 * @var string[]
	 */
	private const REASON_CODES = array(
		'invalid_schema',
		'invalid_adapter',
		'pattern_mismatch',
		'invalid_snapshot',
		'invalid_field',
		'consent_denied',
		'consent_unknown',
		'source_data_empty',
		'plugin_not_detected',
		'no_entry_path',
		'environment_unknown',
		'submitted_key_missing',
		'provider_record_missing',
		'hook_payload_missing',
		'clicktrail_event_missing',
		'provider_record_unverified',
		'hook_payload_only',
		'provider_write_failed',
		'provider_write_unchecked',
	);

	/**
	 * Return the adapter-to-pattern contract.
	 *
	 * @return array<string,string>
	 */
	public static function adapter_patterns(): array {
		return self::ADAPTER_PATTERNS;
	}

	/**
	 * Return the closed reason-code vocabulary.
	 *
	 * @return string[]
	 */
	public static function reason_codes(): array {
		return self::REASON_CODES;
	}

	/**
	 * Compare one presence-only form-readiness snapshot.
	 *
	 * Unknown properties are ignored and never echoed. Every field list is
	 * validated against Attribution_Provider::get_field_mapping().
	 *
	 * @param array<string,mixed> $request Presence-only request.
	 * @return array<string,mixed>
	 */
	public function analyze( array $request ): array {
		$adapter = isset( $request['adapter'] ) && is_string( $request['adapter'] ) ? $request['adapter'] : '';
		$pattern = isset( $request['pattern'] ) && is_string( $request['pattern'] ) ? $request['pattern'] : '';

		if ( self::REQUEST_SCHEMA !== ( $request['schema'] ?? '' ) ) {
			return $this->invalid_report( 'invalid_schema' );
		}
		if ( ! isset( self::ADAPTER_PATTERNS[ $adapter ] ) ) {
			return $this->invalid_report( 'invalid_adapter' );
		}
		if ( self::ADAPTER_PATTERNS[ $adapter ] !== $pattern ) {
			return $this->invalid_report( 'pattern_mismatch', $adapter, self::ADAPTER_PATTERNS[ $adapter ] );
		}

		$context = isset( $request['context'] ) && is_array( $request['context'] ) ? $request['context'] : array();
		$consent = isset( $context['consent'] ) && is_string( $context['consent'] ) ? $context['consent'] : '';
		$source  = isset( $context['source_data'] ) && is_string( $context['source_data'] ) ? $context['source_data'] : '';
		$env     = isset( $context['environment'] ) && is_string( $context['environment'] ) ? $context['environment'] : '';
		if ( ! in_array( $consent, self::CONSENT_STATES, true )
			|| ! in_array( $source, self::SOURCE_STATES, true )
			|| ! in_array( $env, self::ENVIRONMENT_STATES, true )
		) {
			return $this->invalid_report( 'invalid_snapshot', $adapter, $pattern );
		}

		$allowed  = self::allowed_field_names();
		$expected = $this->normalize_keys( $request['expected']['keys'] ?? null, $allowed );
		if ( null === $expected || empty( $expected ) ) {
			return $this->invalid_report( 'invalid_field', $adapter, $pattern );
		}

		$submitted = $this->normalize_surface( $request['submitted'] ?? null, $allowed );
		$stored    = isset( $request['stored'] ) && is_array( $request['stored'] ) ? $request['stored'] : array();
		$surfaces  = isset( $stored['surfaces'] ) && is_array( $stored['surfaces'] ) ? $stored['surfaces'] : array();
		$provider  = $this->normalize_surface( $surfaces['provider_record'] ?? null, $allowed );
		$hook      = $this->normalize_surface( $surfaces['hook_payload'] ?? null, $allowed );
		$event     = $this->normalize_surface( $surfaces['clicktrail_event'] ?? null, $allowed );
		$write     = isset( $stored['write_result'] ) && is_string( $stored['write_result'] ) ? $stored['write_result'] : '';
		if ( null === $submitted || null === $provider || null === $hook || null === $event || ! in_array( $write, self::WRITE_RESULTS, true ) ) {
			return $this->invalid_report( 'invalid_snapshot', $adapter, $pattern );
		}

		$reasons = array();
		$status  = 'pass';
		if ( 'denied' === $consent ) {
			$status    = 'blocked_by_consent';
			$reasons[] = 'consent_denied';
		} elseif ( 'empty' === $source ) {
			$status    = 'no_source_data';
			$reasons[] = 'source_data_empty';
		} elseif ( 'not_detected' === $env ) {
			$status    = 'environment_limited';
			$reasons[] = 'plugin_not_detected';
		} elseif ( 'lite_or_no_entry' === $env ) {
			$status    = 'environment_limited';
			$reasons[] = 'no_entry_path';
		} elseif ( 'unknown' === $env ) {
			$status    = 'unverified';
			$reasons[] = 'environment_unknown';
		}
		if ( 'unknown' === $consent ) {
			$status    = 'pass' === $status ? 'unverified' : $status;
			$reasons[] = 'consent_unknown';
		}

		$fields = array();
		$counts = array(
			'expected'                  => count( $expected ),
			'submitted_present'         => 0,
			'stored_provider_present'   => 0,
			'stored_hook_present'       => 0,
			'stored_clicktrail_present' => 0,
			'missing'                   => 0,
		);

		foreach ( $expected as $field ) {
			$row = array(
				'key'               => $field,
				'expected'          => true,
				'submitted'         => $this->field_state( $field, $submitted ),
				'stored_provider'   => $this->field_state( $field, $provider ),
				'stored_hook'       => $this->field_state( $field, $hook ),
				'stored_clicktrail' => $this->field_state( $field, $event ),
			);

			if ( 'present' === $row['submitted'] ) {
				++$counts['submitted_present'];
			} elseif ( 'absent' === $row['submitted'] ) {
				$reasons[] = 'submitted_key_missing';
			}
			if ( 'present' === $row['stored_provider'] ) {
				++$counts['stored_provider_present'];
			} elseif ( 'absent' === $row['stored_provider'] ) {
				$reasons[] = 'provider_record_missing';
			} elseif ( 'unverified' === $row['stored_provider'] ) {
				$reasons[] = 'provider_record_unverified';
			}
			if ( 'present' === $row['stored_hook'] ) {
				++$counts['stored_hook_present'];
			} elseif ( 'absent' === $row['stored_hook'] ) {
				$reasons[] = 'hook_payload_missing';
			}
			if ( 'present' === $row['stored_clicktrail'] ) {
				++$counts['stored_clicktrail_present'];
			} elseif ( 'absent' === $row['stored_clicktrail'] ) {
				$reasons[] = 'clicktrail_event_missing';
			}

			if ( in_array( 'absent', $row, true ) ) {
				++$counts['missing'];
			}
			$fields[] = $row;
		}

		if ( 'failed' === $write ) {
			$reasons[] = 'provider_write_failed';
			$status    = $this->can_apply_evidence_status( $status ) ? 'missing' : $status;
		} elseif ( 'unchecked' === $write ) {
			$reasons[] = 'provider_write_unchecked';
			$status    = 'pass' === $status ? 'unverified' : $status;
		}
		if ( 'hook_storage' === $pattern && 'observed' === $hook['state'] && 'observed' !== $provider['state'] ) {
			$reasons[] = 'hook_payload_only';
			$status    = 'pass' === $status ? 'unverified' : $status;
		}
		if ( 0 < $counts['missing'] && $this->can_apply_evidence_status( $status ) ) {
			$status = 'missing';
		} elseif ( $this->has_unverified_surface( array( $submitted, $provider, $hook, $event ) ) && 'pass' === $status ) {
			$status = 'unverified';
		}

		$reasons = array_values( array_unique( array_intersect( self::REASON_CODES, $reasons ) ) );
		return array(
			'schema'         => self::REPORT_SCHEMA,
			'adapter'        => $adapter,
			'pattern'        => $pattern,
			'status'         => $status,
			'reason_codes'   => $reasons,
			'fields'         => $fields,
			'counts'         => $counts,
			'evidence_scope' => 'contract_only',
		);
	}

	/**
	 * Return the complete allowlist of form field names.
	 *
	 * @return string[]
	 */
	private static function allowed_field_names(): array {
		if ( ! class_exists( Attribution_Provider::class ) ) {
			return array();
		}
		$fields = array_map(
			static function ( $field ): string {
				return 'ct_' . (string) $field;
			},
			Attribution_Provider::get_field_mapping()
		);
		$fields = array_values( array_unique( $fields ) );
		sort( $fields );
		return $fields;
	}

	/**
	 * Validate and sort a field-name list.
	 *
	 * @param mixed    $keys    Candidate list.
	 * @param string[] $allowed Allowlisted names.
	 * @return string[]|null
	 */
	private function normalize_keys( $keys, array $allowed ): ?array {
		if ( ! is_array( $keys ) || 64 < count( $keys ) ) {
			return null;
		}
		$out = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				return null;
			}
			$out[] = $key;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * Normalize one named evidence surface.
	 *
	 * @param mixed    $surface Candidate surface.
	 * @param string[] $allowed Allowlisted names.
	 * @return array{state:string,keys:string[]}|null
	 */
	private function normalize_surface( $surface, array $allowed ): ?array {
		if ( ! is_array( $surface ) ) {
			return null;
		}
		$state = isset( $surface['state'] ) && is_string( $surface['state'] ) ? $surface['state'] : '';
		$keys  = $this->normalize_keys( $surface['keys_present'] ?? null, $allowed );
		if ( ! in_array( $state, self::SNAPSHOT_STATES, true ) || null === $keys ) {
			return null;
		}
		if ( 'observed' !== $state && ! empty( $keys ) ) {
			return null;
		}
		return array(
			'state' => $state,
			'keys'  => $keys,
		);
	}

	/**
	 * Resolve a field's state on one surface.
	 *
	 * @param string                            $field   Field name.
	 * @param array{state:string,keys:string[]} $surface Surface snapshot.
	 * @return string
	 */
	private function field_state( string $field, array $surface ): string {
		if ( 'not_applicable' === $surface['state'] ) {
			return 'not_applicable';
		}
		if ( 'unavailable' === $surface['state'] ) {
			return 'unverified';
		}
		if ( 'absent' === $surface['state'] ) {
			return 'absent';
		}
		return in_array( $field, $surface['keys'], true ) ? 'present' : 'absent';
	}

	/**
	 * Determine whether a terminal gate allows evidence status to replace it.
	 *
	 * @param string $status Current status.
	 * @return bool
	 */
	private function can_apply_evidence_status( string $status ): bool {
		return in_array( $status, array( 'pass', 'unverified' ), true );
	}

	/**
	 * Check whether any surface remains unavailable.
	 *
	 * @param array<int,array{state:string,keys:string[]}> $surfaces Surfaces.
	 * @return bool
	 */
	private function has_unverified_surface( array $surfaces ): bool {
		foreach ( $surfaces as $surface ) {
			if ( 'unavailable' === $surface['state'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return a privacy-safe invalid request report.
	 *
	 * @param string $reason  Closed reason code.
	 * @param string $adapter Validated adapter, when available.
	 * @param string $pattern Validated pattern, when available.
	 * @return array<string,mixed>
	 */
	private function invalid_report( string $reason, string $adapter = '', string $pattern = '' ): array {
		return array(
			'schema'         => self::REPORT_SCHEMA,
			'adapter'        => isset( self::ADAPTER_PATTERNS[ $adapter ] ) ? $adapter : '',
			'pattern'        => in_array( $pattern, self::ADAPTER_PATTERNS, true ) ? $pattern : '',
			'status'         => 'invalid_request',
			'reason_codes'   => in_array( $reason, self::REASON_CODES, true ) ? array( $reason ) : array( 'invalid_snapshot' ),
			'fields'         => array(),
			'counts'         => array(
				'expected'                  => 0,
				'submitted_present'         => 0,
				'stored_provider_present'   => 0,
				'stored_hook_present'       => 0,
				'stored_clicktrail_present' => 0,
				'missing'                   => 0,
			),
			'evidence_scope' => 'contract_only',
		);
	}
}
