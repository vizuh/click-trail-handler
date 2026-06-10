<?php
/**
 * Unit tests for WooCommerce::build_storefront_event_id().
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-woocommerce.php';

use CLICUTCL\Integrations\WooCommerce;
use PHPUnit\Framework\TestCase;

/**
 * Class WooStorefrontEventIdTest
 *
 * Locks in the deterministic event-ID scheme used by the server-side Woo
 * funnel events (view_item / add_to_cart / begin_checkout). The dispatcher
 * dedup layer relies on these IDs being stable for identical inputs, so any
 * change to the scheme silently breaks anti-double-count behavior.
 */
final class WooStorefrontEventIdTest extends TestCase {

	/** Same inputs always produce the same ID (dedup depends on this). */
	public function test_same_inputs_produce_same_id(): void {
		$first  = WooCommerce::build_storefront_event_id( 'view_item', array( 42, 'sess_abc123' ) );
		$second = WooCommerce::build_storefront_event_id( 'view_item', array( 42, 'sess_abc123' ) );

		$this->assertSame( $first, $second );
		$this->assertSame( 'view_item_42_sess_abc123', $first );
	}

	/** Different parts produce different IDs. */
	public function test_different_parts_produce_different_ids(): void {
		$this->assertNotSame(
			WooCommerce::build_storefront_event_id( 'view_item', array( 42, 'sess_a' ) ),
			WooCommerce::build_storefront_event_id( 'view_item', array( 43, 'sess_a' ) )
		);
	}

	/** The ID is prefixed with the sanitized event name. */
	public function test_id_is_prefixed_with_event_name(): void {
		$id = WooCommerce::build_storefront_event_id( 'begin_checkout', array( 'sess', 'abcd1234' ) );

		$this->assertSame( 0, strpos( $id, 'begin_checkout_' ) );
	}

	/** Parts are sanitized: uppercase lowered, spaces/symbols stripped. */
	public function test_parts_are_sanitized_to_key_charset(): void {
		$id = WooCommerce::build_storefront_event_id( 'add_to_cart', array( 'Key With Spaces', 'S3SS!ON?*', 17 ) );

		$this->assertSame( 'add_to_cart_keywithspaces_s3sson_17', $id );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_\-]+$/', $id );
	}

	/** Empty and non-scalar parts are dropped instead of leaving gaps. */
	public function test_empty_and_non_scalar_parts_are_dropped(): void {
		$id = WooCommerce::build_storefront_event_id( 'view_item', array( '', '!!!', array( 'nested' ), null, 42 ) );

		$this->assertSame( 'view_item_42', $id );
	}

	/** No parts at all still yields the event-name prefix alone. */
	public function test_no_parts_returns_event_name_only(): void {
		$this->assertSame( 'view_item', WooCommerce::build_storefront_event_id( 'view_item', array() ) );
	}

	/** Minute-bucket integers survive as-is for add_to_cart dedup windows. */
	public function test_minute_bucket_part_is_preserved(): void {
		$bucket = absint( 1749550000 / 60 );
		$id     = WooCommerce::build_storefront_event_id( 'add_to_cart', array( 'cartkey', 'sess', $bucket ) );

		$this->assertSame( 'add_to_cart_cartkey_sess_' . $bucket, $id );
	}
}
