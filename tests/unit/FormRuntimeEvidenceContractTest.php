<?php
/**
 * Unit tests for the form runtime-evidence manifests.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/Intelligence/class-form-readiness-analyzer.php';

use CLICUTCL\Intelligence\Form_Readiness_Analyzer;
use PHPUnit\Framework\TestCase;

/**
 * Class FormRuntimeEvidenceContractTest
 */
final class FormRuntimeEvidenceContractTest extends TestCase {

	/**
	 * @dataProvider adapterProvider
	 */
	public function test_manifest_reuses_source_fixture_without_promoting_runtime_status( string $adapter ): void {
		$manifest = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-runtime/v1/' . $adapter . '.json' );
		$request  = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-readiness/v1/' . $adapter . '.json' );
		$result   = ( new Form_Readiness_Analyzer() )->analyze( $request );

		$this->assertSame( 'clicktrail/form-runtime-evidence/v1', $manifest['schema'] );
		$this->assertSame( $adapter, $manifest['adapter'] );
		$this->assertSame( 'tests/fixtures/form-readiness/v1/' . $adapter . '.json', $manifest['source_fixture'] );
		$this->assertTrue( $manifest['runtime_required'] );
		$this->assertFalse( $manifest['runtime_available'] );
		$this->assertSame( 'runtime_unverified', $manifest['certification']['status'] );
		$this->assertSame( 'unverified', $result['status'] );
		$this->assertSame( 'contract_only', $result['evidence_scope'] );
	}

	/**
	 * @dataProvider adapterProvider
	 */
	public function test_manifest_explicitly_covers_each_required_runtime_case( string $adapter ): void {
		$manifest = $this->load_fixture( dirname( __DIR__ ) . '/fixtures/form-runtime/v1/' . $adapter . '.json' );
		$case_ids = array_column( $manifest['cases'], 'id' );

		$this->assertSame(
			array(
				'ajax_cache_path',
				'validation_failure',
				'success',
				'consent_granted',
				'consent_denied',
				'stored_record_inspection',
			),
			$case_ids
		);

		foreach ( $manifest['cases'] as $case ) {
			$this->assertSame( 'unverified', $case['status'] );
			$this->assertSame( 'wordpress_plugin_runtime_unavailable', $case['reason_code'] );
			$this->assertArrayNotHasKey( 'evidence', $case );
		}
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function adapterProvider(): array {
		return array(
			'Contact Form 7' => array( 'cf7' ),
			'Fluent Forms'  => array( 'fluent' ),
			'Gravity Forms' => array( 'gravity' ),
			'WPForms'       => array( 'wpforms' ),
			'Ninja Forms'   => array( 'ninja' ),
			'Elementor Pro' => array( 'elementor' ),
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
