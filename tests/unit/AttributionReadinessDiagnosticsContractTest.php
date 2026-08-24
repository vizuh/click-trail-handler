<?php
/**
 * Tests for the read-only Attribution Readiness Diagnostics response contract.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/admin/traits/trait-admin-diagnostics-ajax.php';
require_once dirname( __DIR__, 2 ) . '/includes/Intelligence/class-attribution-readiness-analyzer.php';

use CLICUTCL\Admin\Admin_Diagnostics_Ajax_Trait;
use PHPUnit\Framework\TestCase;

/**
 * Class AttributionReadinessDiagnosticsContractTest
 */
final class AttributionReadinessDiagnosticsContractTest extends TestCase {

	/**
	 * @var object
	 */
	private $host;

	protected function setUp(): void {
		$this->host = new class {
			use Admin_Diagnostics_Ajax_Trait;
		};
	}

	/**
	 * Invoke the private response allowlist without booting WordPress admin.
	 *
	 * @param array<string,mixed> $result Analyzer-shaped result.
	 * @return array<string,mixed>
	 */
	private function sanitize_result( array $result ): array {
		$method = new ReflectionMethod( $this->host, 'sanitize_attribution_readiness_result' );
		$method->setAccessible( true );
		return $method->invoke( $this->host, $result );
	}

	/**
	 * Invoke the private request-scoped source-alias parser.
	 *
	 * @param string $raw Source alias JSON.
	 * @return array{aliases:array<string,string>,error_code:string}
	 */
	private function parse_aliases( string $raw ): array {
		$method = new ReflectionMethod( $this->host, 'parse_attribution_source_aliases' );
		$method->setAccessible( true );
		return $method->invoke( $this->host, $raw );
	}

	/**
	 * Format the endpoint response without booting WordPress admin.
	 *
	 * @param array<string,mixed> $result Analyzer result.
	 * @param string              $base_url Test URL base.
	 * @return array<string,mixed>
	 */
	private function format_response( array $result, string $base_url ): array {
		$method   = new ReflectionMethod( $this->host, 'format_attribution_readiness_response' );
		$analyzer = new \CLICUTCL\Intelligence\Attribution_Readiness_Analyzer();
		$method->setAccessible( true );
		return $method->invoke( $this->host, $result, $analyzer, $base_url );
	}

	public function test_source_alias_parser_accepts_blank_empty_object_and_valid_mapping(): void {
		$this->assertSame( array( 'aliases' => array(), 'error_code' => '' ), $this->parse_aliases( '' ) );
		$this->assertSame( array( 'aliases' => array(), 'error_code' => '' ), $this->parse_aliases( '{}' ) );
		$this->assertSame(
			array( 'aliases' => array( 'linkedin' => 'linkedin_ads' ), 'error_code' => '' ),
			$this->parse_aliases( '{"linkedin":"linkedin_ads"}' )
		);
	}

	public function test_source_alias_parser_rejects_oversized_input(): void {
		$this->assertSame( 'source_aliases_too_large', $this->parse_aliases( '{' . str_repeat( 'a', 4096 ) )['error_code'] );
	}

	public function test_source_alias_parser_rejects_malformed_json(): void {
		$this->assertSame( 'source_aliases_invalid_json', $this->parse_aliases( '{' )['error_code'] );
	}

	public function test_source_alias_parser_rejects_non_object_json(): void {
		$this->assertSame( 'source_aliases_invalid_shape', $this->parse_aliases( '[]' )['error_code'] );
	}

	public function test_source_alias_parser_rejects_too_many_entries_before_platform_validation(): void {
		$aliases = array();
		for ( $index = 0; $index < 17; $index++ ) {
			$aliases[ 'unknown_' . $index ] = 'value';
		}

		$this->assertSame( 'source_aliases_too_many', $this->parse_aliases( (string) json_encode( $aliases ) )['error_code'] );
	}

	public function test_source_alias_parser_rejects_unknown_platform(): void {
		$this->assertSame( 'source_alias_unknown_platform', $this->parse_aliases( '{"unknown":"source"}' )['error_code'] );
	}

	public function test_source_alias_parser_rejects_invalid_values(): void {
		$this->assertSame( 'source_alias_invalid_value', $this->parse_aliases( '{"linkedin":42}' )['error_code'] );
		$this->assertSame( 'source_alias_invalid_value', $this->parse_aliases( '{"linkedin":""}' )['error_code'] );
	}

	public function test_source_alias_parser_rejects_long_or_invalid_tokens_without_rewriting(): void {
		$too_long = (string) json_encode( array( 'linkedin' => str_repeat( 'a', 65 ) ) );
		$this->assertSame( 'source_alias_invalid_value', $this->parse_aliases( $too_long )['error_code'] );
		$this->assertSame( 'source_alias_invalid_value', $this->parse_aliases( '{"linkedin":"LinkedIn Ads"}' )['error_code'] );
	}

	public function test_valid_alias_survives_analyzer_and_allowlist_without_click_id_value(): void {
		$parsed   = $this->parse_aliases( '{"linkedin":"linkedin_ads"}' );
		$analyzer = new \CLICUTCL\Intelligence\Attribution_Readiness_Analyzer();
		$analysis = $analyzer->analyze(
			array( 'li_fat_id' => 'SECRET_CLICK_ID' ),
			'',
			'',
			array( 'source_aliases' => $parsed['aliases'] )
		);
		$result   = $this->sanitize_result( $analysis );

		$this->assertSame( 'linkedin_ads', $result['recommendations'][0]['suggested_value'] );
		$this->assertSame( 'configured_alias', $result['recommendations'][0]['value_type'] );
		$this->assertSame( 'click_id:li_fat_id', $result['recommendations'][0]['basis'] );
		$this->assertTrue( $result['recommendations'][0]['deterministic'] );
		$this->assertStringNotContainsString( 'SECRET_CLICK_ID', serialize( $result ) );
	}

	public function test_response_allowlist_drops_click_id_values_and_unknown_keys(): void {
		$result = $this->sanitize_result(
			array(
				'status'          => 'attention',
				'fields'          => array(
					'utm_source' => array(
						'status'          => 'missing_inferable',
						'required'        => true,
						'observed_key'    => '',
						'observed_value'  => null,
						'selection_tier'  => 'none',
						'suggested_value' => 'linkedin',
						'deterministic'   => true,
					),
					'unknown_field' => array( 'observed_value' => 'drop-me' ),
				),
				'click_ids'       => array(
					'keys'      => array( 'li_fat_id' ),
					'platforms' => array( 'linkedin' ),
					'values'    => array( 'SECRET_CLICK_ID' ),
				),
				'click_id_values' => array( 'SECRET_TOP_LEVEL' ),
				'source_evidence' => array(
					'platform'      => 'linkedin',
					'basis'         => 'click_id:li_fat_id',
					'signal_type'   => 'ad_click',
					'referrer_host' => '',
				),
				'issues'          => array(
					array( 'code' => 'missing_inferable_utm_source', 'field' => 'utm_source', 'severity' => 'attention', 'basis' => 'click_id:li_fat_id' ),
				),
				'recommendations' => array(
					array( 'code' => 'add_utm_source', 'field' => 'utm_source', 'suggested_value' => 'linkedin', 'deterministic' => true, 'basis' => 'click_id:li_fat_id' ),
				),
				'extra'           => 'drop-me',
			)
		);

		$serialized = serialize( $result );
		$this->assertStringNotContainsString( 'SECRET_CLICK_ID', $serialized );
		$this->assertStringNotContainsString( 'SECRET_TOP_LEVEL', $serialized );
		$this->assertArrayNotHasKey( 'unknown_field', $result['fields'] );
		$this->assertArrayNotHasKey( 'values', $result['click_ids'] );
		$this->assertArrayNotHasKey( 'extra', $result );
		$this->assertSame( array( 'li_fat_id' ), $result['click_ids']['keys'] );
		$this->assertSame( array( 'linkedin' ), $result['click_ids']['platforms'] );
		$this->assertTrue( $result['fields']['utm_source']['deterministic'] );
		$this->assertSame( 'none', $result['fields']['utm_source']['selection_tier'] );
	}

	public function test_response_envelope_adds_only_bounded_copy_test_url(): void {
		$analyzer = new \CLICUTCL\Intelligence\Attribution_Readiness_Analyzer();
		$analysis = $analyzer->analyze(
			array(
				'utm_source'   => 'newsletter',
				'utm_medium'   => 'email',
				'utm_campaign' => 'launch',
			)
		);
		$response = $this->format_response( $analysis, 'https://example.com/' );

		$this->assertSame( array( 'version', 'analysis', 'test_url' ), array_keys( $response ) );
		$this->assertSame( 1, $response['version'] );
		$this->assertSame( 'direct', $response['analysis']['fields']['utm_source']['selection_tier'] );
		$this->assertSame( 'https://example.com/?utm_source=newsletter&utm_medium=email&utm_campaign=launch', $response['test_url'] );
	}

	public function test_response_envelope_omits_test_url_when_builder_blocks(): void {
		$analyzer = new \CLICUTCL\Intelligence\Attribution_Readiness_Analyzer();
		$analysis = $analyzer->analyze( array( 'utm_campaign' => '{campaignid}' ) );
		$response = $this->format_response( $analysis, 'https://example.com/' );

		$this->assertSame( array( 'version', 'analysis' ), array_keys( $response ) );
		$this->assertArrayNotHasKey( 'test_url', $response );
		$this->assertStringNotContainsString( 'campaignid', serialize( $response ) );
	}

	public function test_response_contract_keeps_only_expected_top_level_sections(): void {
		$result = $this->sanitize_result(
			array(
				'status'          => 'pass',
				'fields'          => array(),
				'click_ids'       => array(),
				'source_evidence' => array(),
				'issues'          => array(),
				'recommendations' => array(),
				'raw_payload'     => array( 'gclid' => 'secret' ),
			)
		);

		$this->assertSame(
			array( 'status', 'fields', 'click_ids', 'source_evidence', 'issues', 'recommendations' ),
			array_keys( $result )
		);
	}
}
