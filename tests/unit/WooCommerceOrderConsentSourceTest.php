<?php
/**
 * Consent source for WooCommerce order events (audit F2, WP-05).
 *
 * In wp-admin, REST, cron and WP-CLI the order's checkout consent snapshot
 * decides both the dispatch and the identity gate (geo mode counts as
 * required); a cookie present on that request never does. A front-end request
 * without a live consent state (e.g. a ?wc-api= gateway callback) also follows
 * a stored snapshot; only an order without one falls back to that request's
 * own policy.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace CLICUTCL\Server_Side {
	if ( ! class_exists( Dispatcher::class, false ) ) {
		/**
		 * Recording Dispatcher double; the real one needs the full server-side stack.
		 */
		class Dispatcher {
			/**
			 * Record a commerce dispatch with the explicit consent decision, if any.
			 *
			 * @param array     $payload           Commerce payload.
			 * @param bool|null $consent_authority Explicit consent decision.
			 * @return Adapter_Result
			 */
			public static function dispatch_commerce_event( $payload, $consent_authority = null ) {
				$GLOBALS['clicktrail_test_dispatches'][] = array(
					'payload'   => $payload,
					'authority' => $consent_authority,
				);
				return Adapter_Result::success( 200, 'sent' );
			}

			/**
			 * Record a failure telemetry code.
			 *
			 * @param string $code Failure code.
			 * @return void
			 */
			public static function record_failure( $code ) {
				$GLOBALS['clicktrail_test_failures'][] = $code;
			}

			/**
			 * Adapter key used in trace snapshots.
			 *
			 * @return string
			 */
			public static function get_adapter_key() {
				return 'generic';
			}
		}
	}
}

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}

	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-adapter-result.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-woo-order-privacy.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
	require_once dirname( __DIR__, 2 ) . '/includes/support/class-feature-registry.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-consentdecisioninterface.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-consent-decision.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-identityresolverinterface.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-phone-calling-codes.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-identity-resolver.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-canonicaleventinterfacev2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-eventv2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-event-translator-v1-to-v2.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-claim-lock.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-woocommerce.php';

	use CLICUTCL\Consent\Snapshot_V1;
	use CLICUTCL\Integrations\WooCommerce;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'is_admin' ) ) {
		/**
		 * Request-context toggle: true models a wp-admin request.
		 *
		 * @return bool
		 */
		function is_admin() {
			return ! empty( $GLOBALS['clicktrail_test_is_admin'] );
		}
	}

	if ( ! function_exists( 'wc_get_order' ) ) {
		/**
		 * Return an order or refund double by ID.
		 *
		 * @param int $order_id Order ID.
		 * @return object|null
		 */
		function wc_get_order( $order_id ) {
			return $GLOBALS['clicktrail_test_wc_orders'][ (int) $order_id ] ?? null;
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type ) {
			return 'mysql' === $type ? '2026-11-10 00:00:00' : time();
		}
	}

	if ( ! function_exists( 'wp_parse_args' ) ) {
		function wp_parse_args( $args, $defaults = array() ) {
			return array_merge( (array) $defaults, (array) $args );
		}
	}

	/**
	 * Minimal WC_Order double for the order-event paths.
	 */
	final class ClickTrail_Consent_Source_Order {
		/**
		 * Order metadata.
		 *
		 * @var array<string,mixed>
		 */
		public array $meta;

		/**
		 * @param array<string,mixed> $meta Initial order meta.
		 */
		public function __construct( array $meta ) {
			$this->meta = $meta;
		}

		public function get_id() {
			return 501;
		}

		public function get_meta( $key, $single = true ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( $key, $value ): void {
			$this->meta[ (string) $key ] = $value;
		}

		public function save(): void {}

		public function get_meta_data(): array {
			$out = array();
			foreach ( $this->meta as $key => $value ) {
				$out[] = (object) array(
					'key'   => $key,
					'value' => $value,
				);
			}
			return $out;
		}

		public function get_order_number() {
			return '501';
		}

		public function get_total() {
			return 80.0;
		}

		public function get_total_refunded() {
			return 20.0;
		}

		public function get_currency() {
			return 'EUR';
		}

		public function get_coupon_codes() {
			return array();
		}

		public function get_status() {
			return 'processing';
		}

		public function get_items() {
			return array();
		}

		public function get_billing_email() {
			return 'buyer@example.com';
		}

		public function get_billing_phone() {
			return '';
		}

		public function get_billing_country() {
			return 'PT';
		}

		public function get_customer_ip_address() {
			return '203.0.113.7';
		}

		public function get_customer_user_agent() {
			return 'BuyerAgent/1.0';
		}
	}

	/**
	 * Class WooCommerceOrderConsentSourceTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class WooCommerceOrderConsentSourceTest extends TestCase {
		private const ORDER_ID  = 501;
		private const REFUND_ID = 502;

		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array();
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_is_admin']             = false;
			$GLOBALS['clicktrail_test_dispatches']           = array();
			$GLOBALS['clicktrail_test_failures']             = array();
			$GLOBALS['clicktrail_test_filters']              = array();
			$GLOBALS['clicktrail_test_wc_orders']            = array();
		}

		protected function tearDown(): void {
			$_COOKIE = array();
			unset(
				$GLOBALS['clicktrail_test_consent_mode_enabled'],
				$GLOBALS['clicktrail_test_consent_required'],
				$GLOBALS['clicktrail_test_is_admin'],
				$GLOBALS['clicktrail_test_dispatches'],
				$GLOBALS['clicktrail_test_failures'],
				$GLOBALS['clicktrail_test_filters'],
				$GLOBALS['clicktrail_test_wc_orders']
			);
		}

		/**
		 * F2 outcome 1: buyer denied at checkout, shop manager's cookie granted.
		 */
		public function test_admin_refund_with_denied_snapshot_and_granted_cookie_sends_nothing(): void {
			$order = $this->given_order( $this->snapshot( false ) );
			$this->given_admin_request();
			$this->given_cookie( true );

			( new WooCommerce() )->track_order_refund( self::ORDER_ID, self::REFUND_ID );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'], 'Nothing is dispatched for a buyer who denied consent.' );
			$this->assertArrayNotHasKey( WooCommerce::TRACE_META_KEY, $order->meta, 'No trace (identity/attribution) is stored for a denied buyer.' );
			$this->assertSame( array( 'consent_denied' ), $GLOBALS['clicktrail_test_failures'], 'The skip leaves a diagnostic reason.' );
		}

		/**
		 * F2 outcome 2: buyer granted at checkout, shop manager's cookie denied.
		 */
		public function test_admin_milestone_with_granted_snapshot_and_denied_cookie_follows_the_snapshot(): void {
			$this->given_order( $this->snapshot( true ) );
			$this->given_admin_request();
			$this->given_cookie( false );

			( new WooCommerce() )->track_cancelled_milestone( self::ORDER_ID );

			$dispatch = $this->single_dispatch();
			$this->assertSame( hash( 'sha256', 'buyer@example.com' ), $dispatch['payload']['identity']['hashed_email'] ?? null );
			$this->assertTrue( $dispatch['payload']['consent']['marketing'] );
			$this->assertTrue( $dispatch['authority'], 'The snapshot decision is passed to the dispatcher explicitly.' );
		}

		/**
		 * F2 outcome 3: cookieless gateway/cron request, buyer granted at checkout.
		 */
		public function test_cookieless_request_with_granted_snapshot_keeps_identity(): void {
			$this->given_order( $this->snapshot( true ) );

			( new WooCommerce() )->track_paid_milestone( self::ORDER_ID );

			$dispatch = $this->single_dispatch();
			$this->assertSame( hash( 'sha256', 'buyer@example.com' ), $dispatch['payload']['identity']['hashed_email'] ?? null );
			$this->assertTrue( $dispatch['authority'] );
		}

		public function test_admin_refund_with_granted_snapshot_and_no_cookie_carries_identity(): void {
			$this->given_order( $this->snapshot( true ) );
			$this->given_admin_request();

			( new WooCommerce() )->track_order_refund( self::ORDER_ID, self::REFUND_ID );

			$dispatch = $this->single_dispatch();
			$this->assertSame( 'order_refund', $dispatch['payload']['event_name'] );
			$this->assertSame( hash( 'sha256', 'buyer@example.com' ), $dispatch['payload']['identity']['hashed_email'] ?? null );
			$this->assertTrue( $dispatch['authority'] );
		}

		public function test_front_end_cookieless_callback_in_geo_mode_follows_a_denied_snapshot(): void {
			// Gateway callback on a front-end URL (?wc-api=), no cookie, gateway server outside the configured regions.
			$GLOBALS['clicktrail_test_consent_mode']     = 'geo';
			$GLOBALS['clicktrail_test_consent_required'] = false;
			$order = $this->given_order( $this->snapshot( false ) );

			( new WooCommerce() )->track_paid_milestone( self::ORDER_ID );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'], 'The requester\'s geography does not override a denied snapshot.' );
			$this->assertArrayNotHasKey( WooCommerce::TRACE_META_KEY, $order->meta );
			$this->assertSame( array( 'consent_denied' ), $GLOBALS['clicktrail_test_failures'] );
		}

		public function test_front_end_cookieless_request_in_geo_mode_keeps_the_request_policy(): void {
			// Visitor outside the configured regions, no cookie, order saved without a snapshot (express flow).
			$GLOBALS['clicktrail_test_consent_mode']     = 'geo';
			$GLOBALS['clicktrail_test_consent_required'] = false;
			$this->given_order( null );

			( new WooCommerce() )->track_paid_milestone( self::ORDER_ID );

			$dispatch = $this->single_dispatch();
			$this->assertSame( hash( 'sha256', 'buyer@example.com' ), $dispatch['payload']['identity']['hashed_email'] ?? null );
		}

		public function test_missing_snapshot_under_required_consent_sends_nothing(): void {
			$this->given_order( null );
			$this->given_admin_request();
			$this->given_cookie( true );

			( new WooCommerce() )->track_refunded_milestone( self::ORDER_ID );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertSame( array( 'consent_denied' ), $GLOBALS['clicktrail_test_failures'] );
		}

		public function test_malformed_snapshot_under_required_consent_sends_nothing(): void {
			$this->given_order( '{not json' );
			$this->given_admin_request();
			$this->given_cookie( true );

			( new WooCommerce() )->track_refunded_milestone( self::ORDER_ID );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_geo_mode_outside_customer_request_does_not_use_the_requesters_region(): void {
			// The manager's own region does not require consent; the buyer's snapshot is a denial.
			$GLOBALS['clicktrail_test_consent_mode']     = 'geo';
			$GLOBALS['clicktrail_test_consent_required'] = false;
			$this->given_order( $this->snapshot( false ) );
			$this->given_admin_request();

			( new WooCommerce() )->track_cancelled_milestone( self::ORDER_ID );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
		}

		public function test_consent_not_required_sends_without_snapshot_and_ignores_the_cookie(): void {
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$this->given_order( null );
			$this->given_admin_request();
			$this->given_cookie( false );

			( new WooCommerce() )->track_cancelled_milestone( self::ORDER_ID );

			$dispatch = $this->single_dispatch();
			$this->assertTrue( $dispatch['payload']['consent']['marketing'], 'The outbound consent is not the requester\'s cookie.' );
			$this->assertTrue( $dispatch['authority'] );
		}

		public function test_payload_filter_cannot_replace_the_order_snapshot(): void {
			$this->given_order( $this->snapshot( true ) );
			$this->given_admin_request();
			$GLOBALS['clicktrail_test_filters']['clicutcl_woocommerce_order_milestone_payload'] = static function ( $payload ) {
				$payload['consent'] = array( 'marketing' => false );
				return $payload;
			};

			( new WooCommerce() )->track_cancelled_milestone( self::ORDER_ID );

			$this->assertTrue( $this->single_dispatch()['payload']['consent']['marketing'] );
		}

		public function test_customer_request_live_withdrawal_still_wins_over_granted_snapshot(): void {
			$order = $this->given_order( $this->snapshot( true ) );
			$this->given_cookie( false );

			( new WooCommerce() )->track_paid_milestone( self::ORDER_ID );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_dispatches'] );
			$this->assertArrayNotHasKey( WooCommerce::TRACE_META_KEY, $order->meta );
			$this->assertSame( array( 'consent_denied' ), $GLOBALS['clicktrail_test_failures'] );
		}

		public function test_customer_request_with_live_grant_keeps_live_semantics(): void {
			$this->given_order( $this->snapshot( true ) );
			$this->given_cookie( true );

			( new WooCommerce() )->track_paid_milestone( self::ORDER_ID );

			$dispatch = $this->single_dispatch();
			$this->assertNull( $dispatch['authority'], 'The buyer\'s own request keeps the dispatcher\'s live-cookie rule.' );
			$this->assertSame( hash( 'sha256', 'buyer@example.com' ), $dispatch['payload']['identity']['hashed_email'] ?? null );
		}

		/**
		 * Register the order (and a refund) with an optional stored consent snapshot.
		 *
		 * @param string|null $consent_meta Stored `_clicutcl_consent` value, null for none.
		 * @return ClickTrail_Consent_Source_Order
		 */
		private function given_order( ?string $consent_meta ): ClickTrail_Consent_Source_Order {
			$meta = array( '_clicutcl_lt_source' => 'google' );
			if ( null !== $consent_meta ) {
				$meta[ WooCommerce::CONSENT_META_KEY ] = $consent_meta;
			}
			$order  = new ClickTrail_Consent_Source_Order( $meta );
			$refund = new class() {
				public function get_amount() {
					return 20.0;
				}
				public function get_reason() {
					return 'damaged';
				}
			};

			$GLOBALS['clicktrail_test_wc_orders'][ self::ORDER_ID ]  = $order;
			$GLOBALS['clicktrail_test_wc_orders'][ self::REFUND_ID ] = $refund;

			return $order;
		}

		private function given_admin_request(): void {
			$GLOBALS['clicktrail_test_is_admin'] = true;
		}

		private function given_cookie( bool $granted ): void {
			$_COOKIE['production_consent'] = $granted ? 'granted' : 'denied';
		}

		private function snapshot( bool $granted ): string {
			return (string) wp_json_encode(
				Snapshot_V1::capture(
					array(
						'marketing' => $granted,
						'analytics' => $granted,
					),
					true
				)
			);
		}

		/**
		 * @return array{payload:array,authority:bool|null}
		 */
		private function single_dispatch(): array {
			$this->assertCount( 1, $GLOBALS['clicktrail_test_dispatches'], 'Exactly one dispatch.' );
			return $GLOBALS['clicktrail_test_dispatches'][0];
		}
	}
}
