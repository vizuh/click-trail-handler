<?php
/**
 * Unit tests for Event_Name_Map.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-event-name-map.php';

use CLICUTCL\Tracking\Event_Name_Map;
use PHPUnit\Framework\TestCase;

/**
 * Class EventNameMapTest
 *
 * Locks in the additive GA4/Meta event-name alignment contract: internal
 * names keep flowing unchanged in 'event_name', aligned names ride along in
 * extra keys, and renaming stays opt-in (filter defaults to false).
 */
final class EventNameMapTest extends TestCase {

	/** Case 1: 'lead' maps to GA4 'generate_lead'. */
	public function test_lead_maps_to_generate_lead(): void {
		$this->assertSame( 'generate_lead', Event_Name_Map::to_ga4( 'lead' ) );
	}

	/** Case 2: 'form_submission' also maps to GA4 'generate_lead'. */
	public function test_form_submission_maps_to_generate_lead(): void {
		$this->assertSame( 'generate_lead', Event_Name_Map::to_ga4( 'form_submission' ) );
	}

	/** Case 3: 'qualified_lead' maps to GA4 'qualify_lead'. */
	public function test_qualified_lead_maps_to_qualify_lead(): void {
		$this->assertSame( 'qualify_lead', Event_Name_Map::to_ga4( 'qualified_lead' ) );
	}

	/** Case 4: 'book_appointment' maps to GA4 'working_lead'. */
	public function test_book_appointment_maps_to_working_lead(): void {
		$this->assertSame( 'working_lead', Event_Name_Map::to_ga4( 'book_appointment' ) );
	}

	/** Case 5: 'order_paid' is deliberately unmapped (avoids double 'purchase'). */
	public function test_order_paid_passes_through_unchanged(): void {
		$this->assertSame( 'order_paid', Event_Name_Map::to_ga4( 'order_paid' ) );
	}

	/** Case 6: 'purchase' is already GA4-aligned and passes through as itself. */
	public function test_purchase_passes_through_as_identity(): void {
		$this->assertSame( 'purchase', Event_Name_Map::to_ga4( 'purchase' ) );
	}

	/** Case 7: unknown event names pass through unchanged. */
	public function test_unknown_name_passes_through_unchanged(): void {
		$this->assertSame( 'totally_custom_event', Event_Name_Map::to_ga4( 'totally_custom_event' ) );
	}

	/** Case 8: decorate_body adds ga4_event_name without touching event_name. */
	public function test_decorate_body_adds_ga4_event_name_without_renaming(): void {
		$body = array(
			'event_name'     => 'lead',
			'schema_version' => 1,
			'collector'      => 'sgtm',
		);

		$decorated = Event_Name_Map::decorate_body( $body, 'sgtm' );

		$this->assertSame( 'lead', $decorated['event_name'] );
		$this->assertSame( 'generate_lead', $decorated['ga4_event_name'] );
		$this->assertSame( 1, $decorated['schema_version'] );
		$this->assertSame( 'sgtm', $decorated['collector'] );
		$this->assertArrayNotHasKey( 'source_event_name', $decorated );
	}

	/** Case 9: meta_capi destination also gets platform_event_name. */
	public function test_meta_capi_destination_gets_platform_event_name(): void {
		$decorated = Event_Name_Map::decorate_body(
			array( 'event_name' => 'book_appointment' ),
			'meta_capi'
		);

		$this->assertSame( 'book_appointment', $decorated['event_name'] );
		$this->assertSame( 'working_lead', $decorated['ga4_event_name'] );
		$this->assertSame( 'Schedule', $decorated['platform_event_name'] );
	}

	/** Case 10: non-meta destinations do NOT get platform_event_name. */
	public function test_non_meta_destination_has_no_platform_event_name(): void {
		$decorated = Event_Name_Map::decorate_body(
			array( 'event_name' => 'book_appointment' ),
			'sgtm'
		);

		$this->assertArrayNotHasKey( 'platform_event_name', $decorated );
	}

	/**
	 * Case 11: renaming is off by default for sgtm.
	 *
	 * The bootstrap apply_filters() stub returns the default, and the
	 * 'clicutcl_ga4_rename_outbound' filter defaults to false.
	 */
	public function test_should_rename_is_false_by_default_for_sgtm(): void {
		$this->assertFalse( Event_Name_Map::should_rename( 'sgtm' ) );
	}

	/** Case 12: non-GA4 destinations can never opt into renaming. */
	public function test_should_rename_is_false_for_meta_capi(): void {
		$this->assertFalse( Event_Name_Map::should_rename( 'meta_capi' ) );
	}

	/** Case 13: a body without event_name is returned unchanged. */
	public function test_body_without_event_name_is_returned_unchanged(): void {
		$body = array( 'schema_version' => 2 );

		$this->assertSame( $body, Event_Name_Map::decorate_body( $body, 'sgtm' ) );
	}
}
