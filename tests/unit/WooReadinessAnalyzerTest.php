<?php
/**
 * Unit tests for the pure Woo conversion-readiness analyzer.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Intelligence/class-woo-readiness-analyzer.php';

use CLICUTCL\Intelligence\Woo_Readiness_Analyzer;
use PHPUnit\Framework\TestCase;

/**
 * Class WooReadinessAnalyzerTest
 */
final class WooReadinessAnalyzerTest extends TestCase {

	private Woo_Readiness_Analyzer $analyzer;

	protected function setUp(): void {
		$this->analyzer = new Woo_Readiness_Analyzer();
	}

	/**
	 * @dataProvider fixtureProvider
	 */
	public function test_each_versioned_fixture_emits_only_closed_contract_evidence( string $fixture_path ): void {
		$fixture = $this->load_fixture( $fixture_path );
		$expect  = $fixture['expect'];
		$result  = $this->analyzer->analyze( $fixture );

		$this->assertSame( Woo_Readiness_Analyzer::REPORT_SCHEMA, $result['schema'] );
		$this->assertSame( $fixture['fixture_id'], $result['fixture_id'] );
		$this->assertSame( $expect['report_status'], $result['report_status'] );
		$this->assertSame( $expect['reason_codes'], $result['reason_codes'] );
		$this->assertSame( 'contract_only', $result['evidence_scope'] );
		$this->assertSame( array(), array_diff( $result['reason_codes'], Woo_Readiness_Analyzer::reason_codes() ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function fixtureProvider(): array {
		$files = glob( dirname( __DIR__ ) . '/fixtures/woo-readiness/v1/*.json' );
		sort( $files );
		$out = array();
		foreach ( $files as $file ) {
			$out[ basename( $file, '.json' ) ] = array( $file );
		}
		return $out;
	}

	public function test_fixture_corpus_pins_all_sixteen_scenarios_once(): void {
		$ids = array();
		foreach ( self::fixtureProvider() as $fixture ) {
			$request = $this->load_fixture( $fixture[0] );
			$ids[]   = $request['fixture_id'];
		}
		sort( $ids );

		$this->assertSame(
			array( 'F01', 'F02', 'F03', 'F04', 'F05', 'F06', 'F07', 'F08', 'F09', 'F10', 'F11', 'F12', 'F13', 'F14', 'F15', 'F16' ),
			$ids
		);
	}

	public function test_complete_safe_path_passes_without_findings(): void {
		$request = $this->load_named_fixture( 'F06-payment-complete-milestone.json' );
		$result  = $this->analyzer->analyze( $request );

		$this->assertSame( 'pass', $result['report_status'] );
		$this->assertSame( array(), $result['reason_codes'] );
		$this->assertSame( 0, $result['counts']['findings'] );
		$this->assertSame( 'order_total', $result['value_basis_order'] );
		$this->assertSame( 'item_total_ex_tax', $result['value_basis_items'] );
	}

	public function test_full_refund_risk_is_order_independent(): void {
		$request = $this->load_named_fixture( 'F09-full-refund-sequence.json' );
		foreach ( array( 'object_then_status', 'status_then_object' ) as $sequence ) {
			$request['refund']['sequence_order'] = $sequence;
			$result = $this->analyzer->analyze( $request );

			$this->assertSame( 'inconclusive', $result['report_status'] );
			$this->assertContains( 'refund_sequence_conflict', $result['reason_codes'] );
			$this->assertSame( $sequence, $result['sequence_order'] );
		}
	}

	public function test_unknown_properties_and_values_never_round_trip(): void {
		$request = $this->load_named_fixture( 'F06-payment-complete-milestone.json' );
		$request['unknown_payload'] = array(
			'order_id'    => 'synthetic-order-never-return',
			'email'       => 'identity-never-return@example.invalid',
			'click_id'    => 'click-id-never-return',
			'raw_payload' => 'secret-never-return',
		);

		$serialized = serialize( $this->analyzer->analyze( $request ) );

		$this->assertStringNotContainsString( 'synthetic-order-never-return', $serialized );
		$this->assertStringNotContainsString( 'identity-never-return', $serialized );
		$this->assertStringNotContainsString( 'click-id-never-return', $serialized );
		$this->assertStringNotContainsString( 'secret-never-return', $serialized );
	}

	public function test_invalid_enum_fails_closed_without_echoing_unknown_data(): void {
		$request = $this->load_named_fixture( 'F15-adversarial-invalid-enum.json' );
		$result  = $this->analyzer->analyze( $request );

		$this->assertSame( 'fail', $result['report_status'] );
		$this->assertSame( array( 'fixture_invalid' ), $result['reason_codes'] );
		$this->assertSame( 'unknown', $result['observation_kind'] );
		$this->assertSame( 1, $result['counts']['critical'] );
		$this->assertStringNotContainsString( 'future_unknown_mode', serialize( $result ) );
		$this->assertStringNotContainsString( 'identity-never-return', serialize( $result ) );
	}

	/**
	 * @dataProvider invalidScalarProvider
	 */
	public function test_invalid_scalar_contract_fields_fail_closed( string $path, $value ): void {
		$request = $this->load_named_fixture( 'F06-payment-complete-milestone.json' );
		$parts   = explode( '.', $path );
		$target  = &$request;
		foreach ( array_slice( $parts, 0, -1 ) as $part ) {
			$target = &$target[ $part ];
		}
		$target[ end( $parts ) ] = $value;

		$result = $this->analyzer->analyze( $request );
		$this->assertSame( array( 'fixture_invalid' ), $result['reason_codes'] );
	}

	/**
	 * @return array<string,array{string,mixed}>
	 */
	public static function invalidScalarProvider(): array {
		return array(
			'missing root enum' => array( 'admin_surface', null ),
			'invalid nested enum' => array( 'consent.snapshot_state', 'maybe' ),
			'ttl below range' => array( 'dedup.ttl_days', 0 ),
			'ttl above range' => array( 'dedup.ttl_days', 31 ),
			'ttl wrong type' => array( 'dedup.ttl_days', '7' ),
			'nested privacy field' => array( 'privacy.fields', array( array( 'raw_ip' ) ) ),
		);
	}

	/**
	 * @dataProvider importantBranchProvider
	 */
	public function test_each_important_branch_and_hpos_suppression_is_pinned( string $fixture, string $path, string $value, array $reasons ): void {
		$request = $this->load_named_fixture( $fixture );
		$parts   = explode( '.', $path );
		$target  = &$request;
		foreach ( array_slice( $parts, 0, -1 ) as $part ) {
			$target = &$target[ $part ];
		}
		$target[ end( $parts ) ] = $value;

		$result = $this->analyzer->analyze( $request );
		$this->assertSame( 'inconclusive', $result['report_status'] );
		$this->assertSame( $reasons, $result['reason_codes'] );
		$this->assertSame( count( $reasons ), $result['counts']['important'] );
	}

	/**
	 * @return array<string,array{string,string,string,string[]}>
	 */
	public static function importantBranchProvider(): array {
		return array(
			'multi-currency unresolved' => array( 'F06-payment-complete-milestone.json', 'order.currency_state', 'multi_currency_unresolved', array( 'multi_currency_unresolved' ) ),
			'unexplained value mismatch' => array( 'F06-payment-complete-milestone.json', 'order.reconciliation', 'unexplained_mismatch', array( 'value_mismatch_unexplained' ) ),
			'unconfirmed event pattern'  => array( 'F06-payment-complete-milestone.json', 'event.id_stability', 'unconfirmed', array( 'event_pattern_unconfirmed' ) ),
			'HPOS-aware admin suppression' => array( 'F13-hpos-runtime-unverified.json', 'admin_surface', 'hpos_aware', array( 'hpos_runtime_unverified' ) ),
		);
	}

	public function test_schema_failure_preserves_only_an_allowlisted_fixture_id(): void {
		$request = $this->load_named_fixture( 'F06-payment-complete-milestone.json' );
		$request['schema'] = 'future-schema-never-return';

		$result = $this->analyzer->analyze( $request );
		$this->assertSame( 'F06', $result['fixture_id'] );
		$this->assertSame( array( 'fixture_invalid' ), $result['reason_codes'] );
		$this->assertSame( 1, $result['counts']['critical'] );
		$this->assertStringNotContainsString( 'future-schema-never-return', serialize( $result ) );
	}

	public function test_none_privacy_label_cannot_be_combined_with_other_fields(): void {
		$request = $this->load_named_fixture( 'F06-payment-complete-milestone.json' );
		$request['privacy']['fields'] = array( 'none', 'raw_ip' );

		$result = $this->analyzer->analyze( $request );
		$this->assertSame( array( 'fixture_invalid' ), $result['reason_codes'] );
	}

	/**
	 * @param string $name Fixture filename.
	 * @return array<string,mixed>
	 */
	private function load_named_fixture( string $name ): array {
		return $this->load_fixture( dirname( __DIR__ ) . '/fixtures/woo-readiness/v1/' . $name );
	}

	/**
	 * @param string $path Fixture path.
	 * @return array<string,mixed>
	 */
	private function load_fixture( string $path ): array {
		$decoded = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $decoded );
		return $decoded;
	}
}
