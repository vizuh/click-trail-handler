<?php
/**
 * Boundary security regressions.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-canonicaleventinterfacev2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-eventv2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-queue.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhook-auth.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';

	use CLICUTCL\Core\Attribution_Provider;
	use CLICUTCL\Server_Side\Consent;
	use CLICUTCL\Server_Side\Queue;
	use CLICUTCL\Tracking\EventV2;
	use CLICUTCL\Tracking\Webhook_Auth;
	use PHPUnit\Framework\TestCase;

	final class BoundarySecurityTest extends TestCase {
		protected function tearDown(): void {
			$_COOKIE = array();
			$GLOBALS['clicktrail_test_transients'] = array();
			unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		}

		private const HUBSPOT_URI = '/wp-json/clicutcl/v2/webhooks/hubspot?src=a%3Ab%2Cc';

		private function hubspot_v3_request( string $body, string $secret, string $timestamp, array $headers = array() ): \WP_REST_Request {
			$_SERVER['HTTP_HOST']   = 'shop.example.test';
			$_SERVER['REQUEST_URI'] = self::HUBSPOT_URI;
			// HubSpot signs the URL with %3A and %2C decoded.
			$signature = Webhook_Auth::hubspot_v3_signature( $secret, 'POST', 'https://shop.example.test/wp-json/clicutcl/v2/webhooks/hubspot?src=a:b,c', $body, $timestamp );

			return new \WP_REST_Request(
				$headers + array(
					'X-HubSpot-Signature-v3'      => $signature,
					'X-HubSpot-Request-Timestamp' => $timestamp,
				),
				$body,
				'/clicutcl/v2/webhooks/hubspot'
			);
		}

		private function now_ms(): string {
			return (string) (int) floor( microtime( true ) * 1000 );
		}

		public function test_browser_route_rejects_server_only_events(): void {
			$this->assertTrue( EventV2::is_browser_event_allowed( 'lead' ) );
			$this->assertFalse( EventV2::is_browser_event_allowed( 'purchase' ) );
			$this->assertFalse( EventV2::is_browser_event_allowed( 'client_won' ) );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_configured_consent_cookie_is_used(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE['production_consent'] = '{"marketing":true,"analytics":false}';
			$this->assertSame( array( 'marketing' => true, 'analytics' => false ), Consent::get_state() );

			$_COOKIE['production_consent'] = 'opaque-provider-value';
			$_COOKIE['ct_consent_state']   = '{"marketing":false,"analytics":true}';
			$this->assertSame( array( 'marketing' => false, 'analytics' => true ), Consent::get_state() );
		}

		public function test_retry_payload_drops_raw_transport_identity(): void {
			$payload = Queue::redact_retry_payload(
				array(
					'identity' => array( 'hashed_email' => 'hash', 'ip_address' => '203.0.113.1', 'user_agent' => 'Browser' ),
					'meta'     => array( 'identity' => array( 'ip_address' => '203.0.113.1', 'user_agent' => 'Browser' ) ),
				)
			);

			$this->assertSame( array( 'hashed_email' => 'hash' ), $payload['identity'] );
			$this->assertSame( array(), $payload['meta']['identity'] );
		}

		public function test_typeform_native_signature_is_accepted(): void {
			$body      = '{"event_id":"evt"}';
			$secret    = 'typeform-secret';
			$signature = 'sha256=' . base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
			$request   = new \WP_REST_Request( array( 'Typeform-Signature' => $signature ), $body, '/typeform' );

			$this->assertTrue( Webhook_Auth::verify_request( $request, $secret, 'typeform' ) );
		}

		public function test_hubspot_v1_signature_is_accepted_without_v3_header(): void {
			$body      = '[{"eventId":1}]';
			$secret    = 'hubspot-secret';
			$signature = hash( 'sha256', $secret . $body );
			$request   = new \WP_REST_Request( array( 'X-HubSpot-Signature' => $signature ), $body, '/hubspot' );

			$this->assertTrue( Webhook_Auth::verify_request( $request, $secret, 'hubspot' ) );
		}

		public function test_hubspot_v3_signature_matches_published_vector(): void {
			// Example from HubSpot's "Validate the v3 request signature" guide.
			$body = '[{"eventId":531833541,"subscriptionId":3923621,"portalId":48807704,"appId":16111050,"occurredAt":1752613920733,"subscriptionType":"contact.creation","attemptNumber":0,"objectId":138017612137,"changeFlag":"CREATED","changeSource":"CRM_UI","sourceId":"userId:76023669"}]';

			$this->assertSame(
				'gbj1XPRvUt0noT7i7fXfTzOD4sLzQmf0VT28ZYq0EYg=',
				Webhook_Auth::hubspot_v3_signature( 'cfc68c0b-4b4e-4ef8-b764-95350e4ea479', 'POST', 'https://webhook.site/335453f5-94b3-49d9-b684-a55354d4b8df', $body, '1752613922216' )
			);
		}

		public function test_hubspot_v3_signature_is_accepted(): void {
			$request = $this->hubspot_v3_request( '[{"eventId":2}]', 'hubspot-secret', $this->now_ms() );

			$this->assertTrue( Webhook_Auth::verify_request( $request, 'hubspot-secret', 'hubspot' ) );
		}

		public function test_hubspot_v3_tampered_body_is_rejected(): void {
			$signed   = $this->hubspot_v3_request( '[{"eventId":3}]', 'hubspot-secret', $this->now_ms() );
			$tampered = new \WP_REST_Request(
				array(
					'X-HubSpot-Signature-v3'      => $signed->get_header( 'x-hubspot-signature-v3' ),
					'X-HubSpot-Request-Timestamp' => $signed->get_header( 'x-hubspot-request-timestamp' ),
				),
				'[{"eventId":4}]',
				'/clicutcl/v2/webhooks/hubspot'
			);

			$result = Webhook_Auth::verify_request( $tampered, 'hubspot-secret', 'hubspot' );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'webhook_signature_invalid', $result->get_error_code() );
		}

		public function test_hubspot_v3_stale_timestamp_is_rejected(): void {
			$stale   = (string) ( (int) $this->now_ms() - 301000 );
			$request = $this->hubspot_v3_request( '[{"eventId":5}]', 'hubspot-secret', $stale );

			$result = Webhook_Auth::verify_request( $request, 'hubspot-secret', 'hubspot' );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'webhook_timestamp_invalid', $result->get_error_code() );
		}

		public function test_hubspot_invalid_v3_does_not_fall_back_to_v1(): void {
			$body    = '[{"eventId":6}]';
			$request = $this->hubspot_v3_request(
				$body,
				'hubspot-secret',
				$this->now_ms(),
				array(
					'X-HubSpot-Signature-v3' => 'not-the-v3-signature',
					'X-HubSpot-Signature'    => hash( 'sha256', 'hubspot-secret' . $body ),
				)
			);

			$result = Webhook_Auth::verify_request( $request, 'hubspot-secret', 'hubspot' );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'webhook_signature_invalid', $result->get_error_code() );
		}

		public function test_calendly_custom_signature_remains_supported(): void {
			$body      = '{"event":"invitee.created"}';
			$secret    = 'calendly-secret';
			$timestamp = (string) time();
			$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
			$request   = new \WP_REST_Request(
				array(
					'X-Clicutcl-Timestamp' => $timestamp,
					'X-Clicutcl-Signature' => $signature,
				),
				$body,
				'/calendly'
			);

			$this->assertTrue( Webhook_Auth::verify_request( $request, $secret, 'calendly' ) );
		}

		public function test_attribution_sanitize_truncates_long_field_values(): void {
			$long = str_repeat( 'a', 400 );

			$sanitized = Attribution_Provider::sanitize( array( 'campaign' => $long ) );

			$this->assertSame( 255, strlen( $sanitized['campaign'] ) );
			$this->assertSame( str_repeat( 'a', 255 ), $sanitized['campaign'] );
		}

		public function test_attribution_sanitize_leaves_short_field_values_untouched(): void {
			$sanitized = Attribution_Provider::sanitize( array( 'campaign' => 'spring_sale' ) );

			$this->assertSame( 'spring_sale', $sanitized['campaign'] );
		}
	}
}
