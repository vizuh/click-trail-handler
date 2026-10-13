<?php
/**
 * Top-level click ID precedence: the last touch wins over a frozen first-visit copy.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-canonicaleventinterfacev2.php';
require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-eventv2.php';

use CLICUTCL\Core\Attribution_Provider;
use CLICUTCL\Tracking\EventV2;
use PHPUnit\Framework\TestCase;

/**
 * Class ClickIdPrecedenceTest
 */
final class ClickIdPrecedenceTest extends TestCase {

	/** Cookie written before 1.10.5 by a returning visitor with a new ad click. */
	private const RETURNING = array(
		'gclid'    => 'TEST_GCLID_123',
		'ft_gclid' => 'TEST_GCLID_123',
		'lt_gclid' => 'TEST_PERSIST_456',
	);

	public function test_provider_sanitize_prefers_last_touch(): void {
		$data = Attribution_Provider::sanitize( self::RETURNING );

		$this->assertSame( 'TEST_PERSIST_456', $data['gclid'] );
		$this->assertSame( 'TEST_GCLID_123', $data['ft_gclid'] );
	}

	public function test_provider_sanitize_falls_back_to_first_touch_only_when_empty(): void {
		$this->assertSame( 'FT', Attribution_Provider::sanitize( array( 'ft_gclid' => 'FT' ) )['gclid'] );
		$this->assertSame( 'TOP', Attribution_Provider::sanitize( array( 'gclid' => 'TOP', 'ft_gclid' => 'FT' ) )['gclid'] );
	}

	public function test_get_payload_reads_canonical_cookie_before_legacy(): void {
		$cookies = $_COOKIE;
		try {
			$_COOKIE = array(
				'ct_attribution' => wp_json_encode( array( 'lt_gclid' => 'LEGACY' ) ),
				'attribution'    => wp_json_encode(
					array(
						'lt_gclid'                      => 'CURRENT',
						'attribution_selected_click_id' => 'CURRENT',
					)
				),
			);
			$payload = Attribution_Provider::get_payload();
			$this->assertSame( 'CURRENT', $payload['gclid'] );
			$this->assertSame( 'CURRENT', $payload['attribution_selected_click_id'] );

			$_COOKIE = array( 'ct_attribution' => wp_json_encode( array( 'lt_gclid' => 'LEGACY' ) ) );
			$this->assertSame( 'LEGACY', Attribution_Provider::get_payload()['gclid'] );
		} finally {
			$_COOKIE = $cookies;
		}
	}

	public function test_selected_click_id_is_persisted_by_forms_and_orders(): void {
		$mapping = Attribution_Provider::get_field_mapping();
		$this->assertContains( 'attribution_selected_click_id', $mapping );
		$this->assertContains( 'attribution_selected_click_id_reason', $mapping );
		$this->assertNotContains( 'click_id_history', $mapping );
	}

	public function test_eventv2_prefers_last_touch_and_keeps_top_level_only_ids(): void {
		$returning = EventV2::normalize( array( 'attribution' => self::RETURNING ) );
		$this->assertSame( 'TEST_PERSIST_456', $returning['attribution']['gclid'] );

		// Calendly webhook shape: top-level click ID only, nothing to override it.
		$webhook = EventV2::normalize( array( 'attribution' => array( 'gclid' => 'CALENDLY' ) ) );
		$this->assertSame( 'CALENDLY', $webhook['attribution']['gclid'] );
	}
}
