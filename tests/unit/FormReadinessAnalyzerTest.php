<?php
/**
 * Unit tests for the pure form-readiness evidence comparator.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/Intelligence/class-form-readiness-analyzer.php';

use CLICUTCL\Intelligence\Form_Readiness_Analyzer;
use PHPUnit\Framework\TestCase;

/**
 * Class FormReadinessAnalyzerTest
 */
final class FormReadinessAnalyzerTest extends TestCase {

	private Form_Readiness_Analyzer $analyzer;

	protected function setUp(): void {
		$this->analyzer = new Form_Readiness_Analyzer();
	}

	/**
	 * @dataProvider adapterFixtureProvider
	 */
	public function test_each_adapter_has_a_versioned_privacy_safe_fixture( string $fixture_path ): void {
		$request = $this->load_fixture( $fixture_path );
		$result  = $this->analyzer->analyze( $request );

		$this->assertSame( Form_Readiness_Analyzer::REPORT_SCHEMA, $result['schema'] );
		$this->assertSame( $request['adapter'], $result['adapter'] );
		$this->assertSame( $request['pattern'], $result['pattern'] );
		$this->assertSame( 'unverified', $result['status'] );
		$this->assertSame( 'contract_only', $result['evidence_scope'] );
		$this->assertSame( 2, $result['counts']['expected'] );
		$this->assertSame( 2, $result['counts']['stored_clicktrail_present'] );
		$this->assertSame( 0, $result['counts']['missing'] );
		$this->assertSame( array(), array_diff( $result['reason_codes'], Form_Readiness_Analyzer::reason_codes() ) );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function adapterFixtureProvider(): array {
		$base = dirname( __DIR__ ) . '/fixtures/form-readiness/v1/';
		return array(
			'cf7 automatic hidden fields'       => array( $base . 'cf7.json' ),
			'fluent automatic hidden fields'    => array( $base . 'fluent.json' ),
			'gravity matching fields'            => array( $base . 'gravity.json' ),
			'wpforms matching fields'            => array( $base . 'wpforms.json' ),
			'elementor hook storage'              => array( $base . 'elementor.json' ),
			'ninja hook storage'                  => array( $base . 'ninja.json' ),
		);
	}

	public function test_missing_field_is_attributed_to_the_named_surface(): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/gravity.json' );
		$request['stored']['surfaces']['provider_record']['keys_present'] = array( 'ct_ft_source' );

		$result = $this->analyzer->analyze( $request );

		$this->assertSame( 'missing', $result['status'] );
		$this->assertContains( 'provider_record_missing', $result['reason_codes'] );
		$this->assertSame( 1, $result['counts']['stored_provider_present'] );
		$this->assertSame( 1, $result['counts']['missing'] );
		$this->assertSame( 'absent', $result['fields'][1]['stored_provider'] );
	}

	public function test_consent_denial_is_not_reported_as_adapter_failure(): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/cf7.json' );
		$request['context']['consent'] = 'denied';

		$result = $this->analyzer->analyze( $request );

		$this->assertSame( 'blocked_by_consent', $result['status'] );
		$this->assertContains( 'consent_denied', $result['reason_codes'] );
	}

	public function test_empty_source_data_is_not_reported_as_adapter_failure(): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/cf7.json' );
		$request['context']['source_data'] = 'empty';

		$result = $this->analyzer->analyze( $request );

		$this->assertSame( 'no_source_data', $result['status'] );
		$this->assertContains( 'source_data_empty', $result['reason_codes'] );
	}

	public function test_lite_or_no_entry_environment_is_a_non_failure_terminal(): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/wpforms.json' );
		$request['context']['environment'] = 'lite_or_no_entry';

		$result = $this->analyzer->analyze( $request );

		$this->assertSame( 'environment_limited', $result['status'] );
		$this->assertContains( 'no_entry_path', $result['reason_codes'] );
	}

	public function test_pattern_mismatch_fails_closed_without_echoing_the_request(): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/cf7.json' );
		$request['pattern'] = 'hook_storage';
		$request['raw_submission'] = 'never-echo-this-value';

		$result = $this->analyzer->analyze( $request );

		$this->assertSame( 'invalid_request', $result['status'] );
		$this->assertSame( array( 'pattern_mismatch' ), $result['reason_codes'] );
		$this->assertStringNotContainsString( 'never-echo-this-value', serialize( $result ) );
	}

	public function test_unknown_field_fails_closed_without_echoing_the_field_or_values(): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/cf7.json' );
		$request['expected']['keys'][] = 'ct_email';
		$request['submitted']['values'] = array( 'ct_email' => 'person@example.invalid' );

		$result     = $this->analyzer->analyze( $request );
		$serialized = serialize( $result );

		$this->assertSame( 'invalid_request', $result['status'] );
		$this->assertSame( array( 'invalid_field' ), $result['reason_codes'] );
		$this->assertStringNotContainsString( 'ct_email', $serialized );
		$this->assertStringNotContainsString( 'person@example.invalid', $serialized );
	}

	public function test_adversarial_values_and_identifiers_never_round_trip(): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/fluent.json' );
		$request['submitted']['values'] = array(
			'ct_gclid'     => 'raw-click-id-never-return',
			'ct_visitor_id' => 'visitor-never-return',
		);
		$request['stored']['surfaces']['provider_record']['records'] = array(
			'email' => 'identity-never-return@example.invalid',
		);

		$result     = $this->analyzer->analyze( $request );
		$serialized = serialize( $result );

		$this->assertStringNotContainsString( 'raw-click-id-never-return', $serialized );
		$this->assertStringNotContainsString( 'visitor-never-return', $serialized );
		$this->assertStringNotContainsString( 'identity-never-return', $serialized );
		$this->assertArrayNotHasKey( 'values', $result );
	}

	public function test_failed_provider_write_is_missing_not_verified(): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/fluent.json' );
		$request['stored']['write_result'] = 'failed';

		$result = $this->analyzer->analyze( $request );

		$this->assertSame( 'missing', $result['status'] );
		$this->assertContains( 'provider_write_failed', $result['reason_codes'] );
		$this->assertSame( 0, $result['counts']['missing'] );
	}

	public function test_all_observed_surfaces_with_confirmed_write_pass(): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/fluent.json' );
		$request['stored']['write_result'] = 'ok';

		$result = $this->analyzer->analyze( $request );

		$this->assertSame( 'pass', $result['status'] );
		$this->assertSame( array(), $result['reason_codes'] );
		$this->assertSame( 2, $result['counts']['submitted_present'] );
		$this->assertSame( 2, $result['counts']['stored_provider_present'] );
		$this->assertSame( 2, $result['counts']['stored_hook_present'] );
		$this->assertSame( 2, $result['counts']['stored_clicktrail_present'] );
	}

	/**
	 * @dataProvider contradictorySurfaceProvider
	 */
	public function test_non_observed_surface_with_keys_fails_closed( string $state ): void {
		$request = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/cf7.json' );
		$request['submitted']['state'] = $state;

		$result = $this->analyzer->analyze( $request );

		$this->assertSame( 'invalid_request', $result['status'] );
		$this->assertSame( array( 'invalid_snapshot' ), $result['reason_codes'] );
		$this->assertSame( array(), $result['fields'] );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function contradictorySurfaceProvider(): array {
		return array(
			'absent with keys'         => array( 'absent' ),
			'unavailable with keys'    => array( 'unavailable' ),
			'not applicable with keys' => array( 'not_applicable' ),
		);
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
