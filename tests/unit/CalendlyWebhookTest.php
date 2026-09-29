<?php
/**
 * Calendly native webhook signature and tracking mapping (specs/001, part a).
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-canonicaleventinterfacev2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-eventv2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhook-auth.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhookprovideradapterinterface.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-event-translator-v1-to-v2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/webhooks/class-calendlywebhookadapter.php';

	use CLICUTCL\Tracking\Webhook_Auth;
	use CLICUTCL\Tracking\Webhooks\CalendlyWebhookAdapter;
	use PHPUnit\Framework\TestCase;

	final class CalendlyWebhookTest extends TestCase {
		private const SECRET = 'calendly-signing-key';
		private const BODY   = '{"event":"invitee.created","payload":{"uri":"https://api.calendly.com/scheduled_events/E/invitees/I","email":"lead@example.com","tracking":{"utm_source":"google","utm_medium":"cpc","utm_campaign":"brand","utm_content":null,"utm_term":"clinic","salesforce_uuid":"ref123"}}}';

		protected function tearDown(): void {
			$GLOBALS['clicktrail_test_transients'] = array();
		}

		private function calendly_request( string $timestamp, string $signature, string $body = self::BODY ): \WP_REST_Request {
			return new \WP_REST_Request(
				array( 'Calendly-Webhook-Signature' => 't=' . $timestamp . ',v1=' . $signature ),
				$body,
				'/calendly'
			);
		}

		public function test_native_signature_is_accepted(): void {
			$t   = (string) time();
			$sig = hash_hmac( 'sha256', $t . '.' . self::BODY, self::SECRET );

			$this->assertTrue( Webhook_Auth::verify_request( $this->calendly_request( $t, $sig ), self::SECRET, 'calendly' ) );
		}

		public function test_tampered_body_is_rejected(): void {
			$t   = (string) time();
			$sig = hash_hmac( 'sha256', $t . '.' . self::BODY, self::SECRET );
			$res = Webhook_Auth::verify_request( $this->calendly_request( $t, $sig, str_replace( 'brand', 'other', self::BODY ) ), self::SECRET, 'calendly' );

			$this->assertInstanceOf( \WP_Error::class, $res );
			$this->assertSame( 'webhook_signature_invalid', $res->get_error_code() );
		}

		public function test_stale_timestamp_is_rejected(): void {
			$t   = (string) ( time() - 3600 );
			$sig = hash_hmac( 'sha256', $t . '.' . self::BODY, self::SECRET );
			$res = Webhook_Auth::verify_request( $this->calendly_request( $t, $sig ), self::SECRET, 'calendly' );

			$this->assertInstanceOf( \WP_Error::class, $res );
			$this->assertSame( 'webhook_timestamp_invalid', $res->get_error_code() );
		}

		public function test_malformed_header_is_rejected(): void {
			$request = new \WP_REST_Request( array( 'Calendly-Webhook-Signature' => 'v1=abc' ), self::BODY, '/calendly' );
			$res     = Webhook_Auth::verify_request( $request, self::SECRET, 'calendly' );

			$this->assertInstanceOf( \WP_Error::class, $res );
		}

		public function test_replayed_delivery_is_rejected(): void {
			$t   = (string) time();
			$sig = hash_hmac( 'sha256', $t . '.' . self::BODY, self::SECRET );

			$this->assertTrue( Webhook_Auth::verify_request( $this->calendly_request( $t, $sig ), self::SECRET, 'calendly' ) );
			$res = Webhook_Auth::verify_request( $this->calendly_request( $t, $sig ), self::SECRET, 'calendly' );
			$this->assertInstanceOf( \WP_Error::class, $res );
			$this->assertSame( 'webhook_replay_detected', $res->get_error_code() );
		}

		public function test_tracking_utms_map_to_last_touch_attribution(): void {
			$event = ( new CalendlyWebhookAdapter() )->map_to_canonical( json_decode( self::BODY, true ) );

			$this->assertSame( 'book_appointment', $event['event_name'] );
			$this->assertSame(
				array(
					'lt_source'   => 'google',
					'lt_medium'   => 'cpc',
					'lt_campaign' => 'brand',
					'lt_term'     => 'clinic',
				),
				$event['attribution']
			);
		}

		public function test_created_is_a_booking_and_cancellation_is_ignored(): void {
			$adapter = new CalendlyWebhookAdapter();

			$this->assertSame( 'book_appointment', $adapter->map_to_canonical( array( 'event' => 'invitee.created', 'payload' => array() ) )['event_name'] );
			$this->assertFalse( $adapter->is_ignored_event( array( 'event' => 'invitee.created' ) ) );
			$this->assertTrue( $adapter->is_ignored_event( array( 'event' => 'invitee.canceled' ) ) );
			$this->assertTrue( $adapter->is_ignored_event( array( 'event' => 'routing_form_submission.created' ) ) );
			$this->assertFalse( $adapter->is_ignored_event( array() ) );
		}

		public function test_long_multibyte_utm_is_cut_on_a_character_boundary(): void {
			$payload = json_decode( self::BODY, true );
			$payload['payload']['tracking']['utm_campaign'] = str_repeat( 'ç', 300 );
			$event = ( new CalendlyWebhookAdapter() )->map_to_canonical( $payload );

			$this->assertSame( str_repeat( 'ç', 255 ), $event['attribution']['lt_campaign'] );
		}

		/**
		 * Server-side webhooks carry no visitor cookie. With consent required, the
		 * dispatcher falls back to the event's consent snapshot, then to
		 * Consent::marketing_allowed(); both must deny, so booking UTMs are not
		 * delivered without consent.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_webhook_utms_do_not_carry_consent_when_required(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$_COOKIE = array();

			$event = ( new CalendlyWebhookAdapter() )->map_to_canonical( json_decode( self::BODY, true ) );

			$this->assertTrue( \CLICUTCL\Server_Side\Consent::is_required() );
			$this->assertFalse( \CLICUTCL\Server_Side\Consent::has_state() );
			$this->assertEmpty( $event['consent']['marketing'] ?? false );
			$this->assertFalse( \CLICUTCL\Server_Side\Consent::marketing_allowed() );
		}

		public function test_missing_tracking_yields_empty_attribution(): void {
			$payload = json_decode( self::BODY, true );
			unset( $payload['payload']['tracking'] );
			$event = ( new CalendlyWebhookAdapter() )->map_to_canonical( $payload );

			$this->assertSame( array(), $event['attribution'] );
		}
	}
}
