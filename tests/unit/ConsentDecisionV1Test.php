<?php
/**
 * Unit tests for the pure consent decision v1 contract.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';

use CLICUTCL\Consent\Decision_V1;
use CLICUTCL\Consent\Resolver_V1;
use CLICUTCL\Consent\Snapshot_V1;
use PHPUnit\Framework\TestCase;

/**
 * Class ConsentDecisionV1Test
 */
final class ConsentDecisionV1Test extends TestCase {
	public function test_versioned_fixture_contract(): void {
		$fixture_path = dirname( __DIR__ ) . '/fixtures/consent-decision/v1/scenarios.json';
		$scenarios    = json_decode( (string) file_get_contents( $fixture_path ), true, 512, JSON_THROW_ON_ERROR );

		foreach ( $scenarios as $scenario ) {
			$decision = Resolver_V1::resolve( $scenario['input'] );
			$this->assertSame( $scenario['expected']['decision'], $decision->decision(), $scenario['id'] );
			$this->assertSame( $scenario['expected']['basis'], $decision->basis(), $scenario['id'] );
			$this->assertSame( $scenario['expected']['allows_processing'], $decision->allows_processing(), $scenario['id'] );
			$this->assertSame( 1, json_decode( $decision->to_json(), true, 512, JSON_THROW_ON_ERROR )['schema_version'], $scenario['id'] );
		}
	}

	public function test_not_required_snapshot_is_truthful_policy_not_grant(): void {
		$snapshot = Snapshot_V1::capture( array(), false, 1000 );

		$this->assertSame( Decision_V1::DECISION_UNRESOLVED, $snapshot['decision'] );
		$this->assertSame( Decision_V1::BASIS_NOT_REQUIRED, $snapshot['basis'] );
		$this->assertTrue( $snapshot['marketing'] );
	}

	public function test_legacy_snapshot_is_migrated_with_explicit_provenance(): void {
		$snapshot = Snapshot_V1::normalize( '{"marketing":true,"analytics":false}' );

		$this->assertSame( Decision_V1::SCHEMA_VERSION, $snapshot['schema_version'] );
		$this->assertSame( Decision_V1::BASIS_LEGACY_UNVERSIONED, $snapshot['basis'] );
		$this->assertTrue( $snapshot['marketing'] );
		$this->assertFalse( $snapshot['analytics'] );
	}

	public function test_invalid_v1_snapshot_fails_closed(): void {
		$snapshot = Snapshot_V1::normalize(
			array(
				'schema_version' => 1,
				'decision'       => 'maybe',
				'basis'          => 'cmp',
			)
		);

		$this->assertSame( array(), $snapshot );
	}
}
