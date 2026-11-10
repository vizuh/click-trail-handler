<?php
/**
 * Delivery circuit breaker and queued-failure acknowledgement (audit F3).
 *
 * Every test runs in its own process: the doubles declare namespaced stand-ins that other
 * test files declare differently. The transport is the stub `wp_remote_post` in the doubles,
 * so `$GLOBALS['ct_http']['attempts']` is the number of outbound sends.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

use CLICUTCL\Server_Side\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DeliveryBreakerTest extends TestCase {

	private function boot(): \Delivery_Wpdb {
		if ( ! defined( 'CLICUTCL_DIR' ) ) {
			define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
		}
		require_once dirname( __DIR__ ) . '/helpers/delivery-resilience-doubles.php';
		foreach ( array(
			'Consent/class-decision-v1.php',
			'Consent/class-resolver-v1.php',
			'Consent/class-snapshot-v1.php',
			'server-side/class-consent.php',
			'server-side/class-event.php',
			'server-side/class-adapter-interface.php',
			'server-side/class-adapter-result.php',
			'server-side/class-generic-collector-adapter.php',
			'support/class-feature-registry.php',
			'tracking/class-claim-lock.php',
			'server-side/class-dispatcher.php',
			'server-side/class-queue.php',
		) as $file ) {
			require_once CLICUTCL_DIR . 'includes/' . $file;
		}
		return ct_delivery_boot();
	}

	private function dispatch_many( int $count, string $prefix = 'ev-' ): array {
		$results = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$results[] = Dispatcher::dispatch( ct_delivery_event( $prefix . $i ) );
		}
		return $results;
	}

	public function test_failing_collector_gets_at_most_threshold_sends_and_every_event_is_queued(): void {
		$wpdb = $this->boot();

		$results = $this->dispatch_many( 12 );

		$this->assertSame( 5, $GLOBALS['ct_http']['attempts'], 'Default threshold is 5 live sends per window.' );
		$this->assertCount( 12, $wpdb->rows, 'Every failed or short-circuited event must be queued.' );
		foreach ( array_slice( $results, 5 ) as $result ) {
			$this->assertFalse( $result->success );
			$this->assertFalse( $result->skipped );
			$this->assertSame( 'circuit_open', $result->message );
			$this->assertTrue( $result->meta['queued'] );
		}
	}

	public function test_threshold_is_filterable(): void {
		$this->boot();
		$GLOBALS['clicktrail_test_filters']['clicutcl_delivery_breaker_threshold'] = static fn() => 2;

		$this->dispatch_many( 6 );

		$this->assertSame( 2, $GLOBALS['ct_http']['attempts'] );
	}

	public function test_failures_spread_beyond_the_window_do_not_trip(): void {
		$this->boot();
		$GLOBALS['clicktrail_test_filters']['clicutcl_delivery_breaker_window'] = static fn() => 1;

		$this->dispatch_many( 4, 'a-' );
		sleep( 2 );
		$this->dispatch_many( 4, 'b-' );

		$this->assertSame( 8, $GLOBALS['ct_http']['attempts'] );
	}

	public function test_threshold_zero_disables_the_breaker(): void {
		$this->boot();
		$GLOBALS['clicktrail_test_filters']['clicutcl_delivery_breaker_threshold'] = static fn() => 0;

		$this->dispatch_many( 8 );

		$this->assertSame( 8, $GLOBALS['ct_http']['attempts'] );
	}

	public function test_healthy_collector_sees_every_send_and_no_breaker_state(): void {
		$wpdb                        = $this->boot();
		$GLOBALS['ct_http']['status'] = 200;

		$results = $this->dispatch_many( 12 );

		$this->assertSame( 12, $GLOBALS['ct_http']['attempts'] );
		$this->assertSame( array(), $wpdb->rows );
		$this->assertTrue( $results[11]->success );
		$this->assertSame( array(), $this->breaker_transients() );
	}

	public function test_terminal_rejections_never_trip_the_breaker(): void {
		$wpdb                        = $this->boot();
		$GLOBALS['ct_http']['status'] = 400;

		$this->dispatch_many( 12 );

		$this->assertSame( 12, $GLOBALS['ct_http']['attempts'] );
		$this->assertSame( array(), $wpdb->rows, '4xx terminal rejections are not queued.' );
	}

	public function test_success_resets_the_consecutive_failure_count(): void {
		$this->boot();

		$this->dispatch_many( 4, 'a-' );
		$GLOBALS['ct_http']['status'] = 200;
		$this->dispatch_many( 1, 'b-' );
		$GLOBALS['ct_http']['status'] = 503;
		$this->dispatch_many( 4, 'c-' );

		$this->assertSame( 9, $GLOBALS['ct_http']['attempts'], 'Failures split by a success are not consecutive.' );
	}

	public function test_skipped_events_do_not_count_toward_the_threshold(): void {
		$this->boot();
		$GLOBALS['ct_dedup_duplicates'] = array( 'dup-1', 'dup-2', 'dup-3', 'dup-4', 'dup-5', 'dup-6' );

		$this->dispatch_many( 6, 'dup-' );
		$this->dispatch_many( 5, 'live-' );

		$this->assertSame( 5, $GLOBALS['ct_http']['attempts'], 'Duplicates are skipped before the breaker and the send.' );
	}

	public function test_after_cooldown_one_probe_is_sent_and_success_closes_the_breaker(): void {
		$this->boot();
		$GLOBALS['clicktrail_test_filters']['clicutcl_delivery_breaker_cooldown'] = static fn() => 2;
		$this->dispatch_many( 5, 'trip-' );
		$this->assertSame( 'circuit_open', Dispatcher::dispatch( ct_delivery_event( 'open-1' ) )->message );
		sleep( 3 );

		// While the probe is in flight a second request must not send.
		$GLOBALS['ct_http']['status']  = 200;
		$during_probe                  = null;
		$GLOBALS['ct_http']['on_send'] = static function ( int $attempt ) use ( &$during_probe ) {
			if ( 6 === $attempt ) {
				$during_probe = Dispatcher::dispatch( ct_delivery_event( 'concurrent-1' ) );
			}
		};

		$probe = Dispatcher::dispatch( ct_delivery_event( 'probe-1' ) );

		$this->assertTrue( $probe->success );
		$this->assertSame( 'circuit_open', $during_probe->message );
		$this->assertSame( 6, $GLOBALS['ct_http']['attempts'] );

		$this->dispatch_many( 3, 'after-' );
		$this->assertSame( 9, $GLOBALS['ct_http']['attempts'], 'A successful probe closes the breaker.' );
	}

	public function test_failed_probe_reopens_the_breaker(): void {
		$this->boot();
		$GLOBALS['clicktrail_test_filters']['clicutcl_delivery_breaker_cooldown'] = static fn() => 2;
		$this->dispatch_many( 5, 'trip-' );
		sleep( 3 );

		$probe = Dispatcher::dispatch( ct_delivery_event( 'probe-1' ) );
		$this->assertSame( 6, $GLOBALS['ct_http']['attempts'] );
		$this->assertTrue( $probe->meta['queued'] );

		$next = Dispatcher::dispatch( ct_delivery_event( 'next-1' ) );
		$this->assertSame( 'circuit_open', $next->message );
		$this->assertSame( 6, $GLOBALS['ct_http']['attempts'] );
	}

	public function test_open_breaker_with_a_failing_queue_reports_not_queued(): void {
		$wpdb = $this->boot();
		$this->dispatch_many( 5 );
		$wpdb->fail_inserts = true;

		$result = Dispatcher::dispatch( ct_delivery_event( 'lost-1' ) );

		$this->assertSame( 'circuit_open', $result->message );
		$this->assertFalse( $result->meta['queued'] );
	}

	public function test_lifecycle_update_acknowledges_a_queued_failure_with_2xx(): void {
		$wpdb       = $this->boot();
		$controller = $this->controller();

		$response = $controller->lifecycle_update( $this->lifecycle_request( 'evt-q-1' ) );

		$this->assertIsArray( $response, 'A safely queued event must not be a 500.' );
		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['queued'] );
		$this->assertSame( 'evt-q-1', $response['event_id'] );
		$this->assertSame( 1, $GLOBALS['ct_http']['attempts'] );
		$this->assertCount( 1, $wpdb->rows );
	}

	public function test_lifecycle_update_still_fails_when_the_event_was_neither_sent_nor_queued(): void {
		$wpdb               = $this->boot();
		$wpdb->fail_inserts = true;

		$response = $this->controller()->lifecycle_update( $this->lifecycle_request( 'evt-lost-1' ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'dispatch_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
	}

	public function test_webhook_acknowledges_a_queued_failure_with_2xx(): void {
		$wpdb = $this->boot();
		$body = '{"event":"invitee.created","payload":{"uri":"https://api.calendly.com/scheduled_events/E/invitees/I","email":"lead@example.com","tracking":{"utm_source":"google"}}}';

		$responses = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$responses[] = $this->controller()->ingest_webhook(
				new \WP_REST_Request( array(), str_replace( '/I"', '/I' . $i . '"', $body ), '/clicutcl/v2/webhooks/calendly', array( 'provider' => 'calendly' ) )
			);
		}

		$this->assertSame( 5, $GLOBALS['ct_http']['attempts'], 'Webhook dispatch goes through the breaker too.' );
		$this->assertCount( 7, $wpdb->rows );
		foreach ( $responses as $response ) {
			$this->assertIsArray( $response );
			$this->assertTrue( $response['success'] );
			$this->assertTrue( $response['queued'] );
		}
	}

	private function controller() {
		foreach ( array(
			'tracking/class-canonicaleventinterfacev2.php',
			'tracking/class-eventv2.php',
			'tracking/class-webhookprovideradapterinterface.php',
			'tracking/class-event-translator-v1-to-v2.php',
			'tracking/class-webhook-auth.php',
			'tracking/webhooks/class-calendlywebhookadapter.php',
			'api/class-tracking-controller.php',
		) as $file ) {
			require_once CLICUTCL_DIR . 'includes/' . $file;
		}
		return new \CLICUTCL\Api\Tracking_Controller();
	}

	private function lifecycle_request( string $event_id ): \WP_REST_Request {
		return new \WP_REST_Request(
			array(),
			(string) wp_json_encode(
				array(
					'stage'    => 'qualified_lead',
					'lead_id'  => 'L-1',
					'event_id' => $event_id,
				)
			),
			'/clicutcl/v2/lifecycle/update'
		);
	}

	private function breaker_transients(): array {
		return array_filter(
			$GLOBALS['clicktrail_test_transients'],
			static fn( $key ) => false !== strpos( (string) $key, 'breaker' ),
			ARRAY_FILTER_USE_KEY
		);
	}
}
