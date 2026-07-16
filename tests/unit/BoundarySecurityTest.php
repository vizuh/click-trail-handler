<?php
/**
 * Boundary security regressions.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace CLICUTCL\Modules\Consent_Mode {
	class Consent_Mode_Settings {
		public function get_cookie_name(): string {
			return 'production_consent';
		}
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-canonicaleventinterfacev2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-eventv2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-queue.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhook-auth.php';

	use CLICUTCL\Server_Side\Consent;
	use CLICUTCL\Server_Side\Queue;
	use CLICUTCL\Tracking\EventV2;
	use CLICUTCL\Tracking\Webhook_Auth;
	use PHPUnit\Framework\TestCase;

	final class BoundarySecurityTest extends TestCase {
		protected function tearDown(): void {
			$_COOKIE = array();
			$GLOBALS['clicktrail_test_transients'] = array();
		}

		public function test_browser_route_rejects_server_only_events(): void {
			$this->assertTrue( EventV2::is_browser_event_allowed( 'lead' ) );
			$this->assertFalse( EventV2::is_browser_event_allowed( 'purchase' ) );
			$this->assertFalse( EventV2::is_browser_event_allowed( 'client_won' ) );
		}

		public function test_configured_consent_cookie_is_used(): void {
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

		public function test_hubspot_native_signature_is_accepted(): void {
			$body      = '[{"eventId":1}]';
			$secret    = 'hubspot-secret';
			$signature = hash( 'sha256', $secret . $body );
			$request   = new \WP_REST_Request( array( 'X-HubSpot-Signature' => $signature ), $body, '/hubspot' );

			$this->assertTrue( Webhook_Auth::verify_request( $request, $secret, 'hubspot' ) );
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
	}
}
