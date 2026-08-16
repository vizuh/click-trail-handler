<?php
/**
 * REST permission-check boundary tests for Tracking_Controller and its auth
 * dependencies (Auth token verification, attribution-token sign/verify).
 *
 * These exercise the actual permission_callback methods wired up in
 * register_routes() -- the real security boundary for the v2 REST API --
 * without a live WordPress install.
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
require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-auth.php';
require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhook-auth.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/class-tracking-controller.php';

use CLICUTCL\Api\Tracking_Controller;
use CLICUTCL\Core\Storage\Option_Cache;
use CLICUTCL\Tracking\Auth;
use PHPUnit\Framework\TestCase;

/**
 * Class RestAuthPermissionsTest
 */
final class RestAuthPermissionsTest extends TestCase {

	protected function setUp(): void {
		// Pristine settings for every test; Option_Cache::set() writes straight
		// into the static request cache that Settings::get() reads first, so
		// this is the reliable way to control settings without touching a DB.
		Option_Cache::set( 'clicutcl_tracking_v2', array() );
	}

	protected function tearDown(): void {
		Option_Cache::set( 'clicutcl_tracking_v2', array() );
		$_COOKIE                               = array();
		$GLOBALS['clicktrail_test_transients']   = array();
		$GLOBALS['clicktrail_test_current_user_can'] = false;
		$GLOBALS['clicktrail_test_wp_verify_nonce']  = false;
		$GLOBALS['clicktrail_test_blog_id']          = 1;
		$GLOBALS['clicktrail_test_home_url']         = 'https://example.test';
	}

	// ------------------------------------------------------------------
	// Auth::verify_client_token() -- the token shape the batch/attribution
	// permission checks both rely on.
	// ------------------------------------------------------------------

	public function test_verify_client_token_rejects_missing_token(): void {
		$result = Auth::verify_client_token( '' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_verify_client_token_rejects_malformed_token(): void {
		$result = Auth::verify_client_token( 'not-a-real-token-no-dot' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_verify_client_token_rejects_bad_signature(): void {
		$token  = Auth::mint_client_token();
		$parts  = explode( '.', $token, 2 );
		$tampered = $parts[0] . '.tampered-signature';

		$result = Auth::verify_client_token( $tampered );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_signature', $result->get_error_code() );
	}

	public function test_verify_client_token_accepts_freshly_minted_token(): void {
		$token  = Auth::mint_client_token();
		$claims = Auth::verify_client_token( $token );

		$this->assertIsArray( $claims );
		$this->assertSame( 'example.test', $claims['host'] );
	}

	public function test_verify_client_token_rejects_expired_token(): void {
		$token = $this->forge_client_token(
			array(
				'v'    => 2,
				'iat'  => time() - 1000,
				'exp'  => time() - 10,
				'site' => 'https://example.test',
				'host' => 'example.test',
				'blog' => 1,
			)
		);

		$result = Auth::verify_client_token( $token );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'token_expired', $result->get_error_code() );
	}

	public function test_verify_client_token_rejects_wrong_host(): void {
		$token = $this->forge_client_token(
			array(
				'v'    => 2,
				'iat'  => time(),
				'exp'  => time() + 300,
				'site' => 'https://other.test',
				'host' => 'other.test',
				'blog' => 1,
			)
		);

		$result = Auth::verify_client_token( $token );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'token_site_mismatch', $result->get_error_code() );
	}

	public function test_verify_client_token_rejects_wrong_blog(): void {
		$token = Auth::mint_client_token();
		$GLOBALS['clicktrail_test_blog_id'] = 2;

		$result = Auth::verify_client_token( $token );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'token_blog_mismatch', $result->get_error_code() );
	}

	/**
	 * Forge a token with arbitrary claims, signed with the same key derivation
	 * production code uses -- needed for expired/wrong-host cases where
	 * mint_client_token() enforces sane bounds (TTL clamped to >= 60s, host
	 * always current).
	 *
	 * @param array $claims Claims.
	 * @return string
	 */
	private function forge_client_token( array $claims ): string {
		$json    = wp_json_encode( $claims );
		$payload = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		$key     = hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) );
		$sig     = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $payload, $key, true ) ), '+/', '-_' ), '=' );

		return $payload . '.' . $sig;
	}

	// ------------------------------------------------------------------
	// batch_events_permissions_check
	// ------------------------------------------------------------------

	public function test_batch_events_rejects_missing_token(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request( array(), '{}', '/clicutcl/v2/events/batch' );

		$result = $controller->batch_events_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_batch_events_rejects_malformed_token(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => 'garbage' ),
			'{}',
			'/clicutcl/v2/events/batch'
		);

		$result = $controller->batch_events_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_batch_events_rejects_expired_token(): void {
		$controller = new Tracking_Controller();
		$token      = $this->forge_client_token(
			array(
				'v'    => 2,
				'iat'  => time() - 1000,
				'exp'  => time() - 10,
				'site' => 'https://example.test',
				'host' => 'example.test',
				'blog' => 1,
			)
		);
		$request = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => $token ),
			'{}',
			'/clicutcl/v2/events/batch'
		);

		$result = $controller->batch_events_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'token_expired', $result->get_error_code() );
	}

	public function test_batch_events_rejects_wrong_host_token(): void {
		$controller = new Tracking_Controller();
		$token      = $this->forge_client_token(
			array(
				'v'    => 2,
				'iat'  => time(),
				'exp'  => time() + 300,
				'site' => 'https://other.test',
				'host' => 'other.test',
				'blog' => 1,
			)
		);
		$request = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => $token ),
			'{}',
			'/clicutcl/v2/events/batch'
		);

		$result = $controller->batch_events_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'token_site_mismatch', $result->get_error_code() );
	}

	public function test_batch_events_accepts_valid_token(): void {
		$controller = new Tracking_Controller();
		$token      = Auth::mint_client_token();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => $token ),
			'{}',
			'/clicutcl/v2/events/batch'
		);

		$this->assertTrue( $controller->batch_events_permissions_check( $request ) );
	}

	public function test_batch_events_rejects_when_intake_disabled(): void {
		Option_Cache::set(
			'clicutcl_tracking_v2',
			array( 'feature_flags' => array( 'event_v2' => 0 ) )
		);

		$controller = new Tracking_Controller();
		$token      = Auth::mint_client_token();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => $token ),
			'{}',
			'/clicutcl/v2/events/batch'
		);

		$result = $controller->batch_events_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'event_v2_disabled', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// attribution_token_sign_permissions_check / verify_permissions_check
	// ------------------------------------------------------------------

	public function test_attribution_sign_rejects_missing_token(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request( array(), '{}', '/clicutcl/v2/attribution-token/sign' );

		$result = $controller->attribution_token_sign_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_attribution_sign_rejects_malformed_token(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => 'garbage' ),
			'{}',
			'/clicutcl/v2/attribution-token/sign'
		);

		$result = $controller->attribution_token_sign_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_attribution_sign_rejects_expired_token(): void {
		$controller = new Tracking_Controller();
		$token      = $this->forge_client_token(
			array(
				'v'    => 2,
				'iat'  => time() - 1000,
				'exp'  => time() - 10,
				'site' => 'https://example.test',
				'host' => 'example.test',
				'blog' => 1,
			)
		);
		$request = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => $token ),
			'{}',
			'/clicutcl/v2/attribution-token/sign'
		);

		$result = $controller->attribution_token_sign_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'token_expired', $result->get_error_code() );
	}

	public function test_attribution_sign_rejects_wrong_host_token(): void {
		$controller = new Tracking_Controller();
		$token      = $this->forge_client_token(
			array(
				'v'    => 2,
				'iat'  => time(),
				'exp'  => time() + 300,
				'site' => 'https://other.test',
				'host' => 'other.test',
				'blog' => 1,
			)
		);
		$request = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => $token ),
			'{}',
			'/clicutcl/v2/attribution-token/sign'
		);

		$result = $controller->attribution_token_sign_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'token_site_mismatch', $result->get_error_code() );
	}

	public function test_attribution_sign_accepts_valid_token(): void {
		$controller = new Tracking_Controller();
		$token      = Auth::mint_client_token();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => $token ),
			'{}',
			'/clicutcl/v2/attribution-token/sign'
		);

		$this->assertTrue( $controller->attribution_token_sign_permissions_check( $request ) );
	}

	public function test_attribution_verify_rejects_missing_token(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request( array(), '{}', '/clicutcl/v2/attribution-token/verify' );

		$result = $controller->attribution_token_verify_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_attribution_verify_rejects_malformed_token(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => 'garbage' ),
			'{}',
			'/clicutcl/v2/attribution-token/verify'
		);

		$result = $controller->attribution_token_verify_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_attribution_verify_accepts_valid_token(): void {
		$controller = new Tracking_Controller();
		$token      = Auth::mint_client_token();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Token' => $token ),
			'{}',
			'/clicutcl/v2/attribution-token/verify'
		);

		$this->assertTrue( $controller->attribution_token_verify_permissions_check( $request ) );
	}

	// ------------------------------------------------------------------
	// create_attribution_token() / verify_attribution_token() round trip --
	// real signed-token security logic, fully reachable without a DB.
	// ------------------------------------------------------------------

	public function test_attribution_token_round_trips_allowed_fields(): void {
		$controller = new Tracking_Controller();
		$sign_request = new \WP_REST_Request(
			array(),
			wp_json_encode( array( 'data' => array( 'gclid' => 'abc123', 'unknown_field' => 'dropped' ) ) )
		);

		$signed = $controller->create_attribution_token( $sign_request );
		$this->assertTrue( $signed['success'] );
		$this->assertNotSame( '', $signed['token'] );

		$verify_request = new \WP_REST_Request(
			array(),
			wp_json_encode( array( 'token' => $signed['token'] ) )
		);
		$verified = $controller->verify_attribution_token( $verify_request );

		$this->assertTrue( $verified['success'] );
		$this->assertSame( 'abc123', $verified['data']['gclid'] );
		$this->assertArrayNotHasKey( 'unknown_field', $verified['data'] );
	}

	public function test_create_attribution_token_rejects_no_allowed_fields(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request( array(), wp_json_encode( array( 'data' => array( 'unknown_field' => 'x' ) ) ) );

		$result = $controller->create_attribution_token( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_attribution_data', $result->get_error_code() );
	}

	public function test_verify_attribution_token_rejects_missing_token(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request( array(), '{}' );

		$result = $controller->verify_attribution_token( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_verify_attribution_token_rejects_tampered_signature(): void {
		$controller = new Tracking_Controller();
		$sign_request = new \WP_REST_Request( array(), wp_json_encode( array( 'data' => array( 'gclid' => 'abc123' ) ) ) );
		$signed        = $controller->create_attribution_token( $sign_request );

		$parts    = explode( '.', $signed['token'], 2 );
		$tampered = $parts[0] . '.tampered-signature';

		$verify_request = new \WP_REST_Request( array(), wp_json_encode( array( 'token' => $tampered ) ) );
		$result         = $controller->verify_attribution_token( $verify_request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'invalid_signature', $result->get_error_code() );
	}

	public function test_verify_attribution_token_rejects_wrong_host(): void {
		$controller = new Tracking_Controller();
		$sign_request = new \WP_REST_Request( array(), wp_json_encode( array( 'data' => array( 'gclid' => 'abc123' ) ) ) );
		$signed        = $controller->create_attribution_token( $sign_request );

		// The token was signed for example.test; verifying on a different host
		// (subdomain relation broken) must be rejected.
		$GLOBALS['clicktrail_test_home_url'] = 'https://unrelated-domain.test';

		$verify_request = new \WP_REST_Request( array(), wp_json_encode( array( 'token' => $signed['token'] ) ) );
		$result         = $controller->verify_attribution_token( $verify_request );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'token_site_mismatch', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// lifecycle_permissions_check
	// ------------------------------------------------------------------

	public function test_lifecycle_rejects_missing_token(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request( array(), '{}', '/clicutcl/v2/lifecycle/update' );

		$result = $controller->lifecycle_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'crm_unauthorized', $result->get_error_code() );
	}

	public function test_lifecycle_rejects_wrong_token_via_hash_equals(): void {
		Option_Cache::set(
			'clicutcl_tracking_v2',
			array( 'lifecycle' => array( 'crm_ingestion' => array( 'token' => 'correct-crm-token' ) ) )
		);

		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Crm-Token' => 'wrong-crm-token' ),
			'{}',
			'/clicutcl/v2/lifecycle/update'
		);

		$result = $controller->lifecycle_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'crm_unauthorized', $result->get_error_code() );
	}

	public function test_lifecycle_accepts_correct_token(): void {
		Option_Cache::set(
			'clicutcl_tracking_v2',
			array( 'lifecycle' => array( 'crm_ingestion' => array( 'token' => 'correct-crm-token' ) ) )
		);

		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Crm-Token' => 'correct-crm-token' ),
			'{}',
			'/clicutcl/v2/lifecycle/update'
		);

		$this->assertTrue( $controller->lifecycle_permissions_check( $request ) );
	}

	public function test_lifecycle_rejects_when_no_token_configured(): void {
		// Empty configured token must never match an empty/absent submitted
		// token -- hash_equals('', '') would otherwise be a silent bypass.
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Crm-Token' => '' ),
			'{}',
			'/clicutcl/v2/lifecycle/update'
		);

		$result = $controller->lifecycle_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'crm_unauthorized', $result->get_error_code() );
	}

	public function test_lifecycle_rejects_when_ingestion_disabled_even_with_correct_token(): void {
		Option_Cache::set(
			'clicutcl_tracking_v2',
			array(
				'feature_flags' => array( 'lifecycle_ingestion' => 0 ),
				'lifecycle'     => array( 'crm_ingestion' => array( 'token' => 'correct-crm-token' ) ),
			)
		);

		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array( 'X-Clicutcl-Crm-Token' => 'correct-crm-token' ),
			'{}',
			'/clicutcl/v2/lifecycle/update'
		);

		$result = $controller->lifecycle_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'lifecycle_disabled', $result->get_error_code() );
	}

	public function test_lifecycle_admin_bypasses_token_check(): void {
		$GLOBALS['clicktrail_test_current_user_can'] = true;

		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request( array(), '{}', '/clicutcl/v2/lifecycle/update' );

		$this->assertTrue( $controller->lifecycle_permissions_check( $request ) );
	}

	// ------------------------------------------------------------------
	// webhook_permissions_check
	// ------------------------------------------------------------------

	public function test_webhook_rejects_disabled_provider(): void {
		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array(),
			'{}',
			'/clicutcl/v2/webhooks/typeform',
			array( 'provider' => 'typeform' )
		);

		$result = $controller->webhook_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'provider_disabled', $result->get_error_code() );
	}

	public function test_webhook_rejects_missing_secret_for_enabled_provider(): void {
		Option_Cache::set(
			'clicutcl_tracking_v2',
			array(
				'external_forms' => array(
					'providers' => array(
						'typeform' => array( 'enabled' => 1, 'secret' => '' ),
					),
				),
			)
		);

		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array( 'Typeform-Signature' => 'sha256=irrelevant' ),
			'{"event_id":"evt"}',
			'/clicutcl/v2/webhooks/typeform',
			array( 'provider' => 'typeform' )
		);

		$result = $controller->webhook_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'webhook_secret_missing', $result->get_error_code() );
	}

	public function test_webhook_accepts_valid_signature_for_enabled_provider(): void {
		$secret = 'typeform-shared-secret';
		Option_Cache::set(
			'clicutcl_tracking_v2',
			array(
				'external_forms' => array(
					'providers' => array(
						'typeform' => array( 'enabled' => 1, 'secret' => $secret ),
					),
				),
			)
		);

		$body      = '{"event_id":"evt"}';
		$signature = 'sha256=' . base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );

		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array( 'Typeform-Signature' => $signature ),
			$body,
			'/clicutcl/v2/webhooks/typeform',
			array( 'provider' => 'typeform' )
		);

		$this->assertTrue( $controller->webhook_permissions_check( $request ) );
	}

	public function test_webhook_rejects_when_external_webhooks_feature_disabled(): void {
		Option_Cache::set(
			'clicutcl_tracking_v2',
			array(
				'feature_flags'  => array( 'external_webhooks' => 0 ),
				'external_forms' => array(
					'providers' => array(
						'typeform' => array( 'enabled' => 1, 'secret' => 'x' ),
					),
				),
			)
		);

		$controller = new Tracking_Controller();
		$request    = new \WP_REST_Request(
			array(),
			'{}',
			'/clicutcl/v2/webhooks/typeform',
			array( 'provider' => 'typeform' )
		);

		$result = $controller->webhook_permissions_check( $request );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'external_webhooks_disabled', $result->get_error_code() );
	}
}
