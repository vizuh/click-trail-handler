<?php
/**
 * HubSpot webhook mapping: dotted subscription types and the qualified-lead rule (#107).
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, Squiz.Commenting

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-canonicaleventinterfacev2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-eventv2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhookprovideradapterinterface.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-event-translator-v1-to-v2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/webhooks/class-hubspotwebhookadapter.php';

	use CLICUTCL\Tracking\Webhooks\HubSpotWebhookAdapter;
	use PHPUnit\Framework\TestCase;

	final class HubSpotWebhookTest extends TestCase {
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
	}
}
