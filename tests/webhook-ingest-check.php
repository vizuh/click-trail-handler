<?php
/**
 * Webhook ingest, batch and rate-limit checks with a stubbed Dispatcher.
 *
 * Runs in its own PHP process (see tests/unit/WebhookIngestTest.php) because it
 * replaces CLICUTCL\Server_Side\Dispatcher and the CLICUTCL\Api clock.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side {
	class Dispatcher {
		public static array $calls = array();
		/** @var callable|null */
		public static $next = null;

		public static function dispatch_from_v2( array $event ) {
			self::$calls[] = $event['event_id'];
			return self::$next ? ( self::$next )( $event ) : self::result( true, false, 'ok' );
		}

		public static function result( bool $success, bool $skipped, string $message, array $meta = array() ) {
			return (object) array(
				'success' => $success,
				'skipped' => $skipped,
				'message' => $message,
				'meta'    => $meta,
			);
		}
	}
}

namespace CLICUTCL\Api {
	function time() {
		return $GLOBALS['clicktrail_test_now'] ?? \time();
	}
}

namespace {
	$root = dirname( __DIR__ ) . '/';
	define( 'CLICUTCL_DIR', $root );
	require $root . 'tests/bootstrap.php';
	foreach ( array(
		'includes/Core/Storage/class-option-cache.php',
		'includes/support/class-feature-registry.php',
		'includes/tracking/class-settings.php',
		'includes/tracking/class-auth.php',
		'includes/tracking/class-webhook-auth.php',
		'includes/tracking/class-dedup-store.php',
		'includes/tracking/class-canonicaleventinterfacev2.php',
		'includes/tracking/class-eventv2.php',
		'includes/tracking/class-webhookprovideradapterinterface.php',
		'includes/tracking/class-event-translator-v1-to-v2.php',
		'includes/tracking/webhooks/class-hubspotwebhookadapter.php',
		'includes/tracking/webhooks/class-typeformwebhookadapter.php',
		'includes/tracking/webhooks/class-calendlywebhookadapter.php',
		'includes/tracking/class-identityresolverinterface.php',
		'includes/tracking/class-consentdecisioninterface.php',
		'includes/tracking/class-consent-decision.php',
		'includes/tracking/class-identity-resolver.php',
		'includes/server-side/class-consent.php',
		'includes/api/class-tracking-controller.php',
	) as $file ) {
		require_once $root . $file;
	}

	use CLICUTCL\Api\Tracking_Controller;
	use CLICUTCL\Core\Storage\Option_Cache;
	use CLICUTCL\Server_Side\Dispatcher;
	use CLICUTCL\Tracking\Auth;

	$failures = array();
	$check    = static function ( bool $ok, string $label ) use ( &$failures ) {
		if ( ! $ok ) {
			$failures[] = $label;
		}
	};

	$secret = 'hs-secret';
	Option_Cache::set(
		'clicutcl_tracking_v2',
		array(
			'feature_flags'  => array(
				'external_webhooks' => 1,
				'event_v2'          => 1,
			),
			'external_forms' => array(
				'providers' => array(
					'hubspot' => array(
						'enabled' => 1,
						'secret'  => $secret,
					),
				),
			),
		)
	);
	$controller = new Tracking_Controller();

	$reset = static function () {
		$GLOBALS['clicktrail_test_transients'] = array();
		Dispatcher::$calls                     = array();
		Dispatcher::$next                      = null;
	};
	$hubspot = static function ( array $items ) use ( $controller, $secret ) {
		$body    = json_encode( $items );
		$request = new WP_REST_Request(
			array( 'X-HubSpot-Signature' => hash( 'sha256', $secret . $body ) ),
			$body,
			'/clicutcl/v2/webhooks/hubspot',
			array( 'provider' => 'hubspot' )
		);
		$allowed = $controller->webhook_permissions_check( $request );
		if ( true !== $allowed ) {
			return $allowed;
		}
		return $controller->ingest_webhook( $request );
	};
	$items = static function ( int $count, int $offset ) {
		$out = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$out[] = array(
				'subscriptionType' => 'contact.creation',
				'objectId'         => $offset + $i,
				'eventId'          => $offset + $i,
			);
		}
		return $out;
	};

	// A full 100-notification HubSpot batch is processed, not cut at 50.
	$reset();
	$out = $hubspot( $items( 100, 1000 ) );
	$check( is_array( $out ) && 100 === count( Dispatcher::$calls ) && 100 === count( $out['events'] ), 'hubspot batch of 100 dispatches every item' );

	// Over the cap is rejected instead of silently truncated.
	$reset();
	$out = $hubspot( $items( 101, 2000 ) );
	$check( is_wp_error( $out ) && 'batch_too_large' === $out->get_error_code() && array() === Dispatcher::$calls, 'oversized hubspot batch is rejected, nothing dispatched' );

	// A failure the Dispatcher queued for its own retry is acknowledged.
	$reset();
	Dispatcher::$next = static fn() => Dispatcher::result( false, false, 'http_503', array( 'queued' => true ) );
	$out              = $hubspot( $items( 1, 3000 ) );
	$check( is_array( $out ) && ! empty( $out['success'] ) && ! empty( $out['queued'] ), 'queued delivery failure returns success' );

	// An unqueued server failure releases the replay claim so the identical resend is processed.
	$reset();
	Dispatcher::$next = static fn() => Dispatcher::result( false, false, 'missing_adapter' );
	$first            = $hubspot( $items( 1, 4000 ) );
	Dispatcher::$next = null;
	$retry            = $hubspot( $items( 1, 4000 ) );
	$check( is_wp_error( $first ) && 'dispatch_failed' === $first->get_error_code(), 'unqueued failure returns dispatch_failed' );
	$check( is_array( $retry ) && ! empty( $retry['success'] ) && 2 === count( Dispatcher::$calls ), 'resend after a 5xx is processed, not rejected as a replay' );

	// A successful delivery keeps its replay claim.
	$reset();
	$hubspot( $items( 1, 5000 ) );
	$replay = $hubspot( $items( 1, 5000 ) );
	$check( is_wp_error( $replay ) && 'webhook_replay_detected' === $replay->get_error_code(), 'successful delivery still blocks a replay' );

	// One failing item does not stop the rest of the batch; the resend skips delivered items.
	$reset();
	Dispatcher::$next = static fn() => 2 === count( Dispatcher::$calls ) ? Dispatcher::result( false, false, 'missing_adapter' ) : Dispatcher::result( true, false, 'ok' );
	$first            = $hubspot( $items( 5, 6000 ) );
	$check( is_wp_error( $first ) && 5 === count( Dispatcher::$calls ), 'every batch item is attempted when one fails' );
	Dispatcher::$next = null;
	Dispatcher::$calls = array();
	$retry            = $hubspot( $items( 5, 6000 ) );
	$check( is_array( $retry ) && ! empty( $retry['success'] ) && 1 === count( Dispatcher::$calls ), 'resend redelivers only the failed item' );

	// A string identity on a browser batch event is ignored, not fatal.
	$reset();
	$body    = json_encode(
		array(
			'events' => array(
				array( 'event_name' => 'cta_click', 'event_id' => 'e1', 'event_time' => time(), 'funnel_stage' => 'middle', 'session_id' => 's', 'source_channel' => 'web' ),
				array( 'event_name' => 'cta_click', 'event_id' => 'e2', 'event_time' => time(), 'funnel_stage' => 'middle', 'session_id' => 's', 'source_channel' => 'web', 'identity' => 'x' ),
			),
		)
	);
	$request = new WP_REST_Request( array( 'X-Clicutcl-Token' => Auth::mint_client_token() ), $body, '/clicutcl/v2/events/batch' );
	try {
		$controller->batch_events( $request );
		$check( 2 === count( Dispatcher::$calls ), 'batch with string identity dispatches both events' );
	} catch ( \Throwable $e ) {
		$check( false, 'batch with string identity must not throw: ' . $e->getMessage() );
	}

	// Transient rate limiter resets per window under steady traffic below the limit.
	$reset();
	$_SERVER['REMOTE_ADDR']              = '203.0.113.9';
	$GLOBALS['clicktrail_test_now']      = 1700000000;
	$rate_limit                          = new ReflectionMethod( $controller, 'check_rate_limit' );
	$rate_limit->setAccessible( true );
	$limited = 0;
	for ( $i = 0; $i < 70; $i++ ) {
		if ( is_wp_error( $rate_limit->invoke( $controller, 'events_batch' ) ) ) {
			++$limited;
		}
		$GLOBALS['clicktrail_test_now'] += 30;
	}
	$check( 0 === $limited, 'steady 2 requests per minute is never rate limited' );

	$limited = 0;
	for ( $i = 0; $i < 61; $i++ ) {
		if ( is_wp_error( $rate_limit->invoke( $controller, 'events_batch' ) ) ) {
			++$limited;
		}
	}
	$check( 1 === $limited, 'a burst over the limit inside one window is still limited' );

	if ( $failures ) {
		echo implode( "\n", $failures ), "\n";
		exit( 1 );
	}
	echo "webhook ingest checks passed\n";
}
