<?php
/**
 * Provider secrets and the lifecycle token are stored byte for byte by
 * Tracking_Settings::sanitize(), which receives decoded (unslashed) JSON.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

if ( ! defined( 'CLICUTCL_DIR' ) ) {
	define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
require_once dirname( __DIR__, 2 ) . '/includes/support/class-feature-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-settings.php';

use CLICUTCL\Core\Storage\Option_Cache;
use CLICUTCL\Tracking\Settings as Tracking_Settings;
use PHPUnit\Framework\TestCase;

/**
 * Class TrackingSettingsSecretTest
 */
final class TrackingSettingsSecretTest extends TestCase {

	protected function setUp(): void {
		Option_Cache::set( 'clicutcl_tracking_v2', array() );
	}

	protected function tearDown(): void {
		Option_Cache::set( 'clicutcl_tracking_v2', array() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function secret_provider(): array {
		return array(
			'backslashes'     => array( 'ab\\cd\\9' ),
			'less than'       => array( 'Tf<9xQ2!mZ' ),
			'percent escapes' => array( 'Kx9%3fAbc%20Def' ),
			'double space'    => array( 'two  spaces' ),
			'quotes'          => array( "it's \"quoted\"" ),
		);
	}

	/**
	 * @dataProvider secret_provider
	 */
	public function test_secrets_survive_sanitize_unchanged( string $secret ): void {
		// Same shape the admin app posts, after json_decode().
		$input = json_decode(
			(string) json_encode(
				array(
					'external_forms' => array( 'providers' => array( 'typeform' => array( 'secret' => $secret ) ) ),
					'lifecycle'      => array( 'crm_ingestion' => array( 'token' => $secret ) ),
				)
			),
			true
		);

		$stored = Tracking_Settings::sanitize( $input );
		Option_Cache::set( 'clicutcl_tracking_v2', $stored );

		$this->assertSame( $secret, Tracking_Settings::get_provider_secret( 'typeform' ) );
		$this->assertSame( $secret, Tracking_Settings::get_lifecycle_token() );
	}
}
