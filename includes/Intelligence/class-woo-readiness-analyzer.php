<?php
/**
 * Pure WooCommerce conversion-readiness evidence analyzer.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace CLICUTCL\Intelligence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compare declared, synthetic Woo readiness evidence without runtime reads.
 */
class Woo_Readiness_Analyzer {

	public const REQUEST_SCHEMA = 'clicktrail/woo-readiness/request.v1';
	public const REPORT_SCHEMA  = 'clicktrail/woo-readiness/report.v1';

	/**
	 * Allowed synthetic fixture IDs.
	 *
	 * @var string[]
	 */
	private const FIXTURE_IDS = array(
		'F01',
		'F02',
		'F03',
		'F04',
		'F05',
		'F06',
		'F07',
		'F08',
		'F09',
		'F10',
		'F11',
		'F12',
		'F13',
		'F14',
		'F15',
		'F16',
	);

	/**
	 * Closed request vocabulary by section and field.
	 *
	 * @var array<string,array<string,string[]>>
	 */
	private const ENUMS = array(
		'root'    => array(
			'storage_mode'  => array( 'classic', 'hpos', 'unspecified' ),
			'admin_surface' => array( 'classic_list_and_meta_box', 'hpos_aware', 'none_declared' ),
		),
		'source'  => array(
			'input' => array( 'cookie_primary', 'posted_hidden_fields', 'cookie_fallback_frontend', 'none' ),
		),
		'consent' => array(
			'snapshot_state'    => array( 'granted', 'denied', 'absent' ),
			'live_cookie_state' => array( 'granted', 'denied', 'absent' ),
			'source_used'       => array( 'stored_order_snapshot', 'dispatcher_event_snapshot', 'live_cookie', 'none' ),
			'datalayer_gate'    => array( 'gated', 'ungated', 'not_applicable' ),
		),
		'order'   => array(
			'status'          => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'draft', 'trash', 'unknown' ),
			'transition_kind' => array( 'created', 'payment_complete', 'paid_status_change', 'refunded_full_status', 'refund_object_created', 'cancelled_status', 'other' ),
			'value_state'     => array( 'positive', 'zero', 'negative', 'missing' ),
			'currency_state'  => array( 'present_iso', 'present_non_iso', 'missing', 'multi_currency_unresolved' ),
			'reconciliation'  => array( 'consistent', 'explained_mismatch_allowed', 'unexplained_mismatch' ),
		),
		'event'   => array(
			'name_form'        => array( 'purchase_<order-id>', 'order_paid_<order-id>', 'order_refunded_<order-id>', 'order_refund_<order-id>_<refund-id>', 'order_cancelled_<order-id>' ),
			'id_stability'     => array( 'deterministic', 'per_refund_object', 'unconfirmed' ),
			'dispatch_outcome' => array( 'success', 'queued_error', 'duplicate_event', 'skipped_disabled', 'skipped_environment', 'skipped_endpoint', 'skipped_consent', 'skipped_adapter_missing', 'send_failed', 'unknown' ),
			'marker_semantics' => array( 'set_on_any_skip', 'set_on_terminal_only', 'preexisting_marker_block', 'unset' ),
		),
		'dedup'   => array(
			'state' => array( 'not_checked', 'checked_unmarked', 'marked_after_success', 'race_window_open', 'ttl_expired' ),
		),
		'queue'   => array(
			'state'            => array( 'not_enqueued', 'enqueued', 'replay_direct_to_adapter', 'replay_skips_consent_recheck', 'exhausted' ),
			'unique_key_scope' => array( 'event_name_event_id', 'event_name_event_id_destination' ),
		),
		'refund'  => array(
			'shape'           => array( 'none', 'partial_object', 'full_status_milestone', 'both_sequence' ),
			'amount_encoding' => array( 'positive_absolute', 'zero', 'negative', 'missing', 'not_applicable' ),
			'sequence_order'  => array( 'object_then_status', 'status_then_object', 'single', 'not_applicable' ),
		),
		'privacy' => array(
			'trace_persistence' => array( 'canonical_snapshot_stored', 'summary_updated', 'none' ),
			'diagnostics_path'  => array( 'ajax_lookup_html_structured', 'admin_pretty_json', 'none' ),
			'erasure_coverage'  => array( 'incomplete_meta_keys', 'complete_allowlisted', 'not_applicable' ),
		),
	);

	/**
	 * Allowed privacy-field labels.
	 *
	 * @var string[]
	 */
	private const PRIVACY_FIELDS = array( 'hashed_email', 'hashed_phone', 'raw_ip', 'raw_ua', 'none' );

	/**
	 * Closed reason-code vocabulary.
	 *
	 * @var string[]
	 */
	private const REASON_CODES = array(
		'fixture_invalid',
		'purchase_datalayer_ungated',
		'live_cookie_consent_used',
		'consent_divergent',
		'skip_marked_sent',
		'preexisting_marker_block',
		'duplicate_event_observed',
		'dedup_race_window',
		'queue_consent_recheck_missing',
		'queue_destination_not_unique',
		'currency_missing',
		'currency_non_iso',
		'multi_currency_unresolved',
		'value_mismatch_explained',
		'value_mismatch_unexplained',
		'refund_positive_absolute',
		'refund_sequence_conflict',
		'hpos_runtime_unverified',
		'hpos_admin_hooks_absent',
		'raw_identity_in_snapshot',
		'erasure_coverage_incomplete',
		'event_pattern_unconfirmed',
	);

	/**
	 * Critical readiness findings.
	 *
	 * @var string[]
	 */
	private const CRITICAL_REASONS = array(
		'purchase_datalayer_ungated',
		'live_cookie_consent_used',
		'skip_marked_sent',
		'raw_identity_in_snapshot',
	);

	/**
	 * Important readiness findings.
	 *
	 * @var string[]
	 */
	private const IMPORTANT_REASONS = array(
		'consent_divergent',
		'dedup_race_window',
		'queue_consent_recheck_missing',
		'queue_destination_not_unique',
		'currency_missing',
		'currency_non_iso',
		'multi_currency_unresolved',
		'value_mismatch_unexplained',
		'refund_sequence_conflict',
		'hpos_runtime_unverified',
		'hpos_admin_hooks_absent',
		'erasure_coverage_incomplete',
		'event_pattern_unconfirmed',
	);

	/**
	 * Analyze one synthetic Woo readiness snapshot.
	 *
	 * Unknown properties are ignored. Unknown or missing enum values fail closed.
	 * No arbitrary request value is copied into the report.
	 *
	 * @param array<string,mixed> $request Synthetic evidence request.
	 * @return array<string,mixed>
	 */
	public function analyze( array $request ): array {
		$fixture_id = isset( $request['fixture_id'] ) && is_string( $request['fixture_id'] ) ? $request['fixture_id'] : '';
		if ( self::REQUEST_SCHEMA !== ( $request['schema'] ?? '' ) || ! in_array( $fixture_id, self::FIXTURE_IDS, true ) ) {
			return $this->invalid_report( $fixture_id );
		}

		$normalized = $this->normalize_request( $request );
		if ( null === $normalized ) {
			return $this->invalid_report( $fixture_id );
		}

		$reasons    = $this->derive_reasons( $normalized );
		$critical   = count( array_intersect( $reasons, self::CRITICAL_REASONS ) );
		$important  = count( array_intersect( $reasons, self::IMPORTANT_REASONS ) );
		$status     = 0 < $critical ? 'fail' : ( 0 < $important ? 'inconclusive' : 'pass' );
		$consent    = $normalized['consent'];
		$divergence = $this->consent_divergence( $consent['snapshot_state'], $consent['live_cookie_state'] );

		return array(
			'schema'                 => self::REPORT_SCHEMA,
			'fixture_id'             => $fixture_id,
			'report_status'          => $status,
			'reason_codes'           => $reasons,
			'evidence_scope'         => 'contract_only',
			'observation_kind'       => 'observed',
			'storage_mode'           => $normalized['storage_mode'],
			'admin_surface'          => $normalized['admin_surface'],
			'source_input'           => $normalized['source']['input'],
			'source_provenance'      => 'indeterminate',
			'consent_snapshot_state' => $consent['snapshot_state'],
			'consent_source_used'    => $consent['source_used'],
			'consent_divergence'     => $divergence,
			'datalayer_gate'         => $consent['datalayer_gate'],
			'order_status'           => $normalized['order']['status'],
			'transition_kind'        => $normalized['order']['transition_kind'],
			'event_name_form'        => $normalized['event']['name_form'],
			'event_id_stability'     => $normalized['event']['id_stability'],
			'dispatch_outcome'       => $normalized['event']['dispatch_outcome'],
			'marker_semantics'       => $normalized['event']['marker_semantics'],
			'dedup_state'            => $normalized['dedup']['state'],
			'dedup_ttl_days'         => $normalized['dedup']['ttl_days'],
			'queue_state'            => $normalized['queue']['state'],
			'queue_unique_key'       => $normalized['queue']['unique_key_scope'],
			'value_basis_order'      => 'order_total',
			'value_basis_items'      => 'item_total_ex_tax',
			'value_state'            => $normalized['order']['value_state'],
			'reconciliation'         => $normalized['order']['reconciliation'],
			'currency_state'         => $normalized['order']['currency_state'],
			'refund_shape'           => $normalized['refund']['shape'],
			'refund_amount_encoding' => $normalized['refund']['amount_encoding'],
			'sequence_order'         => $normalized['refund']['sequence_order'],
			'privacy_fields'         => $normalized['privacy']['fields'],
			'trace_persistence'      => $normalized['privacy']['trace_persistence'],
			'diagnostics_path'       => $normalized['privacy']['diagnostics_path'],
			'erasure_coverage'       => $normalized['privacy']['erasure_coverage'],
			'counts'                 => array(
				'findings'  => count( $reasons ),
				'critical'  => $critical,
				'important' => $important,
			),
		);
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
	 * Normalize all required request fields.
	 *
	 * @param array<string,mixed> $request Request.
	 * @return array<string,mixed>|null
	 */
	private function normalize_request( array $request ): ?array {
		$normalized = array();
		foreach ( self::ENUMS['root'] as $field => $allowed ) {
			$value = $this->enum_value( $request, $field, $allowed );
			if ( null === $value ) {
				return null;
			}
			$normalized[ $field ] = $value;
		}

		foreach ( self::ENUMS as $section => $fields ) {
			if ( 'root' === $section ) {
				continue;
			}
			$input = $request[ $section ] ?? null;
			if ( ! is_array( $input ) ) {
				return null;
			}
			$normalized[ $section ] = array();
			foreach ( $fields as $field => $allowed ) {
				$value = $this->enum_value( $input, $field, $allowed );
				if ( null === $value ) {
					return null;
				}
				$normalized[ $section ][ $field ] = $value;
			}
		}

		$ttl = $request['dedup']['ttl_days'] ?? null;
		if ( ! is_int( $ttl ) || 1 > $ttl || 30 < $ttl ) {
			return null;
		}
		$normalized['dedup']['ttl_days'] = $ttl;

		$privacy_fields = $request['privacy']['fields'] ?? null;
		if ( ! is_array( $privacy_fields ) || array() === $privacy_fields ) {
			return null;
		}
		foreach ( $privacy_fields as $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, self::PRIVACY_FIELDS, true ) ) {
				return null;
			}
		}
		$privacy_fields = array_values( array_unique( $privacy_fields ) );
		if ( in_array( 'none', $privacy_fields, true ) && 1 < count( $privacy_fields ) ) {
			return null;
		}
		sort( $privacy_fields );
		$normalized['privacy']['fields'] = $privacy_fields;

		return $normalized;
	}

	/**
	 * Read one closed enum value.
	 *
	 * @param array<string,mixed> $input Input array.
	 * @param string              $field Field name.
	 * @param string[]            $allowed Allowed values.
	 * @return string|null
	 */
	private function enum_value( array $input, string $field, array $allowed ): ?string {
		$value = $input[ $field ] ?? null;
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : null;
	}

	/**
	 * Derive readiness findings from the normalized evidence.
	 *
	 * @param array<string,mixed> $input Normalized input.
	 * @return string[]
	 */
	private function derive_reasons( array $input ): array {
		$reasons = array();
		$consent = $input['consent'];
		$event   = $input['event'];
		$order   = $input['order'];
		$refund  = $input['refund'];
		$privacy = $input['privacy'];

		if ( 'ungated' === $consent['datalayer_gate'] ) {
			$reasons[] = 'purchase_datalayer_ungated';
		}
		if ( 'live_cookie' === $consent['source_used'] ) {
			$reasons[] = 'live_cookie_consent_used';
		}
		if ( 'divergent' === $this->consent_divergence( $consent['snapshot_state'], $consent['live_cookie_state'] ) ) {
			$reasons[] = 'consent_divergent';
		}
		if ( 0 === strpos( $event['dispatch_outcome'], 'skipped_' ) && 'set_on_any_skip' === $event['marker_semantics'] ) {
			$reasons[] = 'skip_marked_sent';
		}
		if ( 'preexisting_marker_block' === $event['marker_semantics'] ) {
			$reasons[] = 'preexisting_marker_block';
		}
		if ( 'duplicate_event' === $event['dispatch_outcome'] ) {
			$reasons[] = 'duplicate_event_observed';
		}
		if ( 'race_window_open' === $input['dedup']['state'] ) {
			$reasons[] = 'dedup_race_window';
		}
		if ( 'replay_skips_consent_recheck' === $input['queue']['state'] ) {
			$reasons[] = 'queue_consent_recheck_missing';
		}
		if ( 'not_enqueued' !== $input['queue']['state'] && 'event_name_event_id' === $input['queue']['unique_key_scope'] ) {
			$reasons[] = 'queue_destination_not_unique';
		}
		if ( 'missing' === $order['currency_state'] ) {
			$reasons[] = 'currency_missing';
		} elseif ( 'present_non_iso' === $order['currency_state'] ) {
			$reasons[] = 'currency_non_iso';
		} elseif ( 'multi_currency_unresolved' === $order['currency_state'] ) {
			$reasons[] = 'multi_currency_unresolved';
		}
		if ( 'explained_mismatch_allowed' === $order['reconciliation'] ) {
			$reasons[] = 'value_mismatch_explained';
		} elseif ( 'unexplained_mismatch' === $order['reconciliation'] ) {
			$reasons[] = 'value_mismatch_unexplained';
		}
		if ( 'positive_absolute' === $refund['amount_encoding'] ) {
			$reasons[] = 'refund_positive_absolute';
		}
		if ( 'both_sequence' === $refund['shape'] ) {
			$reasons[] = 'refund_sequence_conflict';
		}
		if ( 'hpos' === $input['storage_mode'] ) {
			$reasons[] = 'hpos_runtime_unverified';
			if ( 'hpos_aware' !== $input['admin_surface'] ) {
				$reasons[] = 'hpos_admin_hooks_absent';
			}
		}
		if ( in_array( 'raw_ip', $privacy['fields'], true ) || in_array( 'raw_ua', $privacy['fields'], true ) ) {
			$reasons[] = 'raw_identity_in_snapshot';
		}
		if ( 'incomplete_meta_keys' === $privacy['erasure_coverage'] ) {
			$reasons[] = 'erasure_coverage_incomplete';
		}
		if ( 'unconfirmed' === $event['id_stability'] ) {
			$reasons[] = 'event_pattern_unconfirmed';
		}

		return array_values( array_intersect( self::REASON_CODES, array_unique( $reasons ) ) );
	}

	/**
	 * Compare stored and live consent observations.
	 *
	 * @param string $snapshot Stored snapshot state.
	 * @param string $live Live-cookie state.
	 * @return string
	 */
	private function consent_divergence( string $snapshot, string $live ): string {
		if ( 'absent' === $snapshot && 'absent' === $live ) {
			return 'not_applicable';
		}
		return $snapshot === $live ? 'aligned' : 'divergent';
	}

	/**
	 * Return a privacy-safe invalid request report.
	 *
	 * @param string $fixture_id Valid fixture ID, when available.
	 * @return array<string,mixed>
	 */
	private function invalid_report( string $fixture_id = '' ): array {
		return array(
			'schema'           => self::REPORT_SCHEMA,
			'fixture_id'       => in_array( $fixture_id, self::FIXTURE_IDS, true ) ? $fixture_id : '',
			'report_status'    => 'fail',
			'reason_codes'     => array( 'fixture_invalid' ),
			'evidence_scope'   => 'contract_only',
			'observation_kind' => 'unknown',
			'counts'           => array(
				'findings'  => 1,
				'critical'  => 1,
				'important' => 0,
			),
		);
	}
}
