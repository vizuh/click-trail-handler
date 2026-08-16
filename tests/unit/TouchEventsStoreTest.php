<?php
/**
 * Touch_Events_Store::build_row() -- the pure decision logic behind the
 * structured touch-events write path (v1.9.0). Covers the consent gate and
 * the dual attribution-shape derivation (nested first_touch/last_touch from
 * WooCommerce vs flat ft_/lt_ keys from browser events and forms).
 *
 * insert() itself requires a live $wpdb and is not exercised here, same
 * constraint QueueRetryTest documents for class-queue.php's DB-touching
 * methods.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/database/class-touch-events-store.php';

use CLICUTCL\Database\Touch_Events_Store;
use PHPUnit\Framework\TestCase;

/**
 * Class TouchEventsStoreTest
 */
final class TouchEventsStoreTest extends TestCase {

	/**
	 * Minimal valid Server_Side\Event::to_array() payload shape.
	 *
	 * @param array $overrides Keys to override.
	 * @return array
	 */
	private function event_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'event_name'  => 'purchase',
				'event_id'    => 'purchase_123',
				'timestamp'   => 1_700_000_000,
				'source'      => 'server',
				'page'        => array(),
				'wa'          => array(),
				'form'        => array(),
				'commerce'    => array(),
				'attribution' => array(),
				'identity'    => array(),
				'consent'     => array(),
				'meta'        => array(),
			),
			$overrides
		);
	}

	public function test_consent_denied_skips_the_write_entirely(): void {
		$row = Touch_Events_Store::build_row( $this->event_data(), false, 1 );

		$this->assertNull( $row );
	}

	public function test_missing_event_name_skips_the_write(): void {
		$row = Touch_Events_Store::build_row( $this->event_data( array( 'event_name' => '' ) ), true, 1 );

		$this->assertNull( $row );
	}

	public function test_flat_attribution_prefers_last_touch_for_current_touch_fields(): void {
		$row = Touch_Events_Store::build_row(
			$this->event_data(
				array(
					'attribution' => array(
						'ft_source'   => 'google',
						'ft_medium'   => 'cpc',
						'ft_campaign' => 'spring',
						'lt_source'   => 'newsletter',
						'lt_medium'   => 'email',
						'lt_campaign' => 'winback',
					),
				)
			),
			true,
			1
		);

		$this->assertSame( 'google', $row['ft_source'] );
		$this->assertSame( 'cpc', $row['ft_medium'] );
		$this->assertSame( 'spring', $row['ft_campaign'] );
		$this->assertSame( 'newsletter', $row['touch_source'] );
		$this->assertSame( 'email', $row['touch_medium'] );
		$this->assertSame( 'winback', $row['touch_campaign'] );
	}

	public function test_flat_attribution_falls_back_to_first_touch_when_no_last_touch(): void {
		$row = Touch_Events_Store::build_row(
			$this->event_data(
				array(
					'attribution' => array(
						'ft_source' => 'google',
						'ft_medium' => 'cpc',
					),
				)
			),
			true,
			1
		);

		$this->assertSame( 'google', $row['touch_source'] );
		$this->assertSame( 'cpc', $row['touch_medium'] );
		$this->assertNull( $row['touch_campaign'] );
	}

	public function test_nested_woocommerce_attribution_shape_is_also_understood(): void {
		$row = Touch_Events_Store::build_row(
			$this->event_data(
				array(
					'attribution' => array(
						'first_touch' => array(
							'source'   => 'facebook',
							'medium'   => 'social',
							'campaign' => 'launch',
						),
						'last_touch'  => array(
							'source' => 'direct',
						),
					),
				)
			),
			true,
			1
		);

		$this->assertSame( 'facebook', $row['ft_source'] );
		$this->assertSame( 'social', $row['ft_medium'] );
		$this->assertSame( 'launch', $row['ft_campaign'] );
		// Last-touch source present but medium/campaign are not -- current
		// touch is per-field last-touch-if-present, else first-touch.
		$this->assertSame( 'direct', $row['touch_source'] );
		$this->assertSame( 'social', $row['touch_medium'] );
		$this->assertSame( 'launch', $row['touch_campaign'] );
	}

	public function test_visitor_id_prefers_hashed_email_over_session_id(): void {
		$row = Touch_Events_Store::build_row(
			$this->event_data(
				array(
					'identity' => array( 'hashed_email' => 'abc123hash' ),
					'meta'     => array( 'session_id' => 's_deadbeef' ),
				)
			),
			true,
			1
		);

		$this->assertSame( 'abc123hash', $row['visitor_id'] );
		$this->assertSame( 's_deadbeef', $row['session_id'] );
	}

	public function test_visitor_id_falls_back_to_session_id_when_no_identity(): void {
		$row = Touch_Events_Store::build_row(
			$this->event_data( array( 'meta' => array( 'session_id' => 's_deadbeef' ) ) ),
			true,
			1
		);

		$this->assertSame( 's_deadbeef', $row['visitor_id'] );
	}

	public function test_commerce_and_order_id_are_mapped_from_meta_and_commerce(): void {
		$row = Touch_Events_Store::build_row(
			$this->event_data(
				array(
					'commerce' => array(
						'value'    => 49.99,
						'currency' => 'EUR',
					),
					'meta'     => array( 'order_id' => 456 ),
				)
			),
			true,
			1
		);

		$this->assertSame( 456, $row['order_id'] );
		$this->assertSame( 49.99, $row['amount'] );
		$this->assertSame( 'EUR', $row['currency'] );
	}

	public function test_zero_order_id_is_stored_as_null_not_zero(): void {
		$row = Touch_Events_Store::build_row(
			$this->event_data( array( 'meta' => array( 'order_id' => 0 ) ) ),
			true,
			1
		);

		$this->assertNull( $row['order_id'] );
	}

	public function test_funnel_stage_and_source_channel_default_when_absent(): void {
		$row = Touch_Events_Store::build_row( $this->event_data( array( 'source' => '' ) ), true, 1 );

		$this->assertSame( 'unknown', $row['funnel_stage'] );
		$this->assertSame( 'web', $row['source_channel'] );
	}

	public function test_blog_id_is_passed_through(): void {
		$row = Touch_Events_Store::build_row( $this->event_data(), true, 7 );

		$this->assertSame( 7, $row['blog_id'] );
	}

	public function test_created_at_is_derived_from_event_timestamp(): void {
		$row = Touch_Events_Store::build_row( $this->event_data( array( 'timestamp' => 1_700_000_000 ) ), true, 1 );

		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1_700_000_000 ), $row['created_at'] );
	}
}
