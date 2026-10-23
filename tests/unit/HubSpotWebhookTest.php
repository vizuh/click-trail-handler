<?php
/**
 * HubSpot webhook mapping: dotted subscription types, the qualified-lead rule (#107) and
 * the subscription-type allowlist (#128).
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, Squiz.Commenting

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}

	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-canonicaleventinterfacev2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-eventv2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhookprovideradapterinterface.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-event-translator-v1-to-v2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/webhooks/class-hubspotwebhookadapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
	require_once dirname( __DIR__, 2 ) . '/includes/support/class-feature-registry.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-auth.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhook-auth.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/class-tracking-controller.php';

	use CLICUTCL\Api\Tracking_Controller;
	use CLICUTCL\Tracking\Webhooks\HubSpotWebhookAdapter;
	use PHPUnit\Framework\TestCase;

	final class HubSpotWebhookTest extends TestCase {
		protected function tearDown(): void {
			unset( $GLOBALS['clicktrail_test_filters'] );
		}

		private function change( string $property, string $value ): array {
			return array(
				'subscriptionType' => 'contact.propertyChange',
				'objectId'         => 501,
				'propertyName'     => $property,
				'propertyValue'    => $value,
			);
		}

		public function test_lifecycle_change_to_mql_or_sql_is_a_qualified_lead(): void {
			$adapter = new HubSpotWebhookAdapter();
			$mql     = $this->change( 'lifecyclestage', 'marketingqualifiedlead' );
			$sql     = $this->change( 'lifecyclestage', 'salesqualifiedlead' );

			$this->assertFalse( $adapter->is_ignored_event( $mql ) );
			$this->assertSame( 'qualified_lead', $adapter->map_to_canonical( $mql )['event_name'] );
			$this->assertSame( 'qualified_lead', $adapter->map_to_canonical( $sql )['event_name'] );
			$this->assertNotSame(
				$adapter->map_to_canonical( $mql )['event_id'],
				$adapter->map_to_canonical( $sql )['event_id'],
				'MQL and SQL for one contact are separate events'
			);
		}

		public function test_other_property_changes_are_ignored(): void {
			$adapter = new HubSpotWebhookAdapter();

			$this->assertTrue( $adapter->is_ignored_event( $this->change( 'email', 'lead@example.com' ) ) );
			$this->assertTrue( $adapter->is_ignored_event( $this->change( 'lifecyclestage', 'lead' ) ) );
			$this->assertTrue( $adapter->is_ignored_event( $this->change( 'lifecyclestage', 'customer' ) ) );
		}

		public function test_legacy_key_case_and_missing_value(): void {
			$adapter = new HubSpotWebhookAdapter();
			$legacy  = array(
				'eventType'     => 'Contact.PropertyChange',
				'objectId'      => 503,
				'propertyName'  => 'LifecycleStage',
				'propertyValue' => 'MarketingQualifiedLead',
			);

			$this->assertSame( 'qualified_lead', $adapter->map_to_canonical( $legacy )['event_name'], 'eventType key and case-insensitive match' );
			$this->assertTrue( $adapter->is_ignored_event( array( 'subscriptionType' => 'contact.propertyChange', 'objectId' => 503, 'propertyName' => 'lifecyclestage' ) ), 'no propertyValue never qualifies' );
			$this->assertTrue( $adapter->is_ignored_event( array( 'subscriptionType' => 'contact.propertyChange', 'propertyName' => array( 'x' ), 'propertyValue' => array( 'y' ) ) ), 'non-scalar values read as empty' );
		}

		public function test_event_ids_dedupe_retries_but_not_reentries(): void {
			$adapter = new HubSpotWebhookAdapter();
			$first   = $this->change( 'lifecyclestage', 'marketingqualifiedlead' ) + array( 'eventId' => 9001 );
			$retry   = $first;
			$reentry = array( 'eventId' => 9002 ) + $first;

			$this->assertSame( $adapter->map_to_canonical( $first )['event_id'], $adapter->map_to_canonical( $retry )['event_id'], 'a retry dedupes' );
			$this->assertNotSame( $adapter->map_to_canonical( $first )['event_id'], $adapter->map_to_canonical( $reentry )['event_id'], 'a later re-entry counts' );
			$this->assertSame(
				'hs_' . md5( 'contactcreation|502' ),
				$adapter->map_to_canonical( array( 'subscriptionType' => 'contact.creation', 'objectId' => 502 ) )['event_id'],
				'non-qualified IDs keep the pre-#107 basis'
			);
		}

		public function test_contact_creation_stays_a_lead(): void {
			$adapter  = new HubSpotWebhookAdapter();
			$creation = array(
				'subscriptionType' => 'contact.creation',
				'objectId'         => 502,
			);

			$this->assertFalse( $adapter->is_ignored_event( $creation ) );
			$event = $adapter->map_to_canonical( $creation );
			$this->assertSame( 'lead', $event['event_name'] );
			$this->assertSame( 'contact.creation', $event['meta']['provider_event'] );
		}

		public function test_non_conversion_subscription_types_are_ignored(): void {
			$adapter = new HubSpotWebhookAdapter();

			foreach ( array( 'contact.deletion', 'contact.merge', 'contact.restore', 'contact.associationChange', 'contact.privacyDeletion', 'deal.creation', 'unknown.type', '' ) as $type ) {
				$payload = array(
					'subscriptionType' => $type,
					'objectId'         => 601,
				);
				$this->assertFalse( $adapter->supports( $payload ), $type );
				$this->assertTrue( $adapter->is_ignored_event( $payload ), $type );
			}
			$this->assertTrue( $adapter->is_ignored_event( array() ), 'a payload without a subscription type is ignored' );
		}

		public function test_ignored_batch_is_acknowledged_without_recording_an_event(): void {
			$body    = (string) json_encode(
				array(
					array( 'subscriptionType' => 'contact.deletion', 'objectId' => 701, 'eventId' => 1 ),
					array( 'subscriptionType' => 'contact.merge', 'objectId' => 702, 'eventId' => 2 ),
					array( 'subscriptionType' => 'contact.privacyDeletion', 'objectId' => 703, 'eventId' => 3 ),
				)
			);
			$request = new \WP_REST_Request( array(), $body, '/clicutcl/v2/webhooks/hubspot', array( 'provider' => 'hubspot' ) );

			// Ignored payloads return before dedup and dispatch, so no event is recorded.
			$result = ( new Tracking_Controller() )->ingest_webhook( $request );

			$this->assertIsArray( $result, 'acknowledged with 2xx, not a WP_Error' );
			$this->assertTrue( $result['success'] );
			$this->assertCount( 3, $result['events'] );
			foreach ( $result['events'] as $event ) {
				$this->assertSame( array( 'success' => true, 'ignored' => true ), $event );
			}
		}

		public function test_single_ignored_payload_is_acknowledged(): void {
			$request = new \WP_REST_Request( array(), '{"subscriptionType":"contact.deletion","objectId":704}', '/clicutcl/v2/webhooks/hubspot', array( 'provider' => 'hubspot' ) );

			$this->assertSame( array( 'success' => true, 'ignored' => true ), ( new Tracking_Controller() )->ingest_webhook( $request ) );
		}

		public function test_filter_extends_the_allowlist(): void {
			$adapter = new HubSpotWebhookAdapter();
			$deal    = array(
				'subscriptionType' => 'deal.creation',
				'objectId'         => 801,
			);

			$GLOBALS['clicktrail_test_filters']['clicutcl_hubspot_webhook_event_types'] = static fn( array $types ): array => array_merge( $types, array( 'deal.creation' ) );

			$this->assertTrue( $adapter->supports( $deal ) );
			$this->assertFalse( $adapter->is_ignored_event( $deal ) );
			$this->assertSame( 'lead', $adapter->map_to_canonical( $deal )['event_name'] );
			$this->assertTrue( $adapter->is_ignored_event( $this->change( 'email', 'lead@example.com' ) ), 'property changes stay gated by the qualified rule' );
			$this->assertFalse( $adapter->is_ignored_event( $this->change( 'lifecyclestage', 'salesqualifiedlead' ) ) );
			$this->assertTrue( $adapter->is_ignored_event( array( 'subscriptionType' => 'contact.deletion', 'objectId' => 802 ) ) );
		}
	}
}
