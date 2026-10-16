<?php
/**
 * Dispatcher consent gate by request context: a stored order snapshot decides outside the
 * buyer's own request; operator cookies and server geography must not override it.
 * Runs in its own PHP process (see tests/unit/ConsentContextTest.php).
 *
 * @package ClickTrail
 */
namespace CLICUTCL\Modules\Consent_Mode {
	class Consent_Mode_Settings {
		public function is_consent_mode_enabled() { return true; }
		public function get_mode() { return $GLOBALS['mode']; }
		// geo: request country decides (emulates request_matches_regions()).
		public function is_consent_required_for_request() { return 'strict' === $GLOBALS['mode'] ? true : $GLOBALS['request_in_region']; }
		public function get_cookie_name() { return 'ct_consent'; }
	}
}
namespace CLICUTCL\Server_Side {
	class Settings { public static function get() { return array( 'enabled' => true, 'endpoint_url' => 'https://collector.test', 'adapter' => 'generic' ); } }
}
namespace CLICUTCL\Tracking {
	class Dedup_Store { public static function is_duplicate( ...$a ) { return false; } public static function mark( ...$a ) {} }
}
namespace CLICUTCL\Database {
	class Installer { const DB_VERSION_OPTION = 'db_version'; }
	class Touch_Events_Store { public static function record( $e, $allowed, $b ) { $GLOBALS['touch_allowed'] = $allowed; } }
}
namespace {
	function apply_filters( $tag, $value, ...$args ) { return $value; }
	function get_option( $key, $default = false ) { return 'db_version' === $key ? 4 : $default; }
	$root = dirname( __DIR__ );
	require $root . '/tests/bootstrap.php';
	function esc_url_raw( $u ) { return $u; } if ( ! function_exists( 'wp_get_referer' ) ) { function wp_get_referer() { return false; } }
	function wp_get_environment_type() { return 'production'; }
	function get_transient( $k ) { return false; }
	function set_transient( ...$a ) { return true; }
	function wp_remote_post( $url, $args ) { $GLOBALS['sent'][] = json_decode( $args['body'], true ); return array(); }
	function wp_remote_retrieve_response_code( $r ) { return 200; }
	function wp_remote_retrieve_body( $r ) { return ''; }
	foreach ( array( 'Consent/class-decision-v1.php', 'Consent/class-resolver-v1.php', 'Consent/class-snapshot-v1.php',
		'server-side/class-consent.php', 'server-side/class-event.php', 'server-side/class-adapter-interface.php',
		'server-side/class-adapter-result.php', 'server-side/class-generic-collector-adapter.php',
		'support/class-feature-registry.php', 'server-side/class-dispatcher.php', 'server-side/class-queue.php' ) as $f ) { require $root . '/includes/' . $f; }

	$capture = static fn( bool $marketing ) => \CLICUTCL\Consent\Snapshot_V1::capture( array( 'marketing' => $marketing, 'analytics' => $marketing ), true );
	$granted = json_encode( array( 'marketing' => true, 'analytics' => true ) );
	$denied  = json_encode( array( 'marketing' => false, 'analytics' => false ) );

	// label => [ mode, request in region, cookie, is_admin, stored marketing (null = no snapshot), expect sent ].
	$cases = array(
		'strict, admin with granted cookie, buyer stored denial'      => array( 'strict', true, $granted, true, false, false ),
		'strict, webhook, no cookie, buyer stored denial'             => array( 'strict', true, null, false, false, false ),
		'geo, webhook from outside region, buyer stored denial'       => array( 'geo', false, null, false, false, false ),
		'strict, admin with denied cookie, buyer stored grant'        => array( 'strict', true, $denied, true, true, true ),
		'strict, webhook, no cookie, buyer stored grant'              => array( 'strict', true, null, false, true, true ),
		'strict, buyer request, later withdrawal, stored grant'       => array( 'strict', true, $denied, false, true, false ),
		'strict, buyer request, later grant, stored denial'           => array( 'strict', true, $granted, false, false, true ),
		'geo, outside region, no snapshot'                            => array( 'geo', false, null, false, null, true ),
		'strict, buyer request, granted cookie, no snapshot'          => array( 'strict', true, $granted, false, null, true ),
		'strict, no cookie, no snapshot'                              => array( 'strict', true, null, false, null, false ),
	);

	$failures = array();
	foreach ( $cases as $label => list( $mode, $in_region, $cookie, $admin, $stored, $expect ) ) {
		$GLOBALS['mode']                     = $mode;
		$GLOBALS['request_in_region']        = $in_region;
		$GLOBALS['sent']                     = array();
		$GLOBALS['clicktrail_test_is_admin'] = $admin;
		$_COOKIE                             = $cookie ? array( 'ct_consent' => $cookie ) : array();

		$payload = array( 'event_name' => 'order_paid', 'event_id' => 'order_paid_' . md5( $label ), 'order_id' => 42, 'value' => 99.5, 'currency' => 'EUR' );
		if ( null !== $stored ) {
			$payload['consent'] = $capture( $stored );
		}
		\CLICUTCL\Server_Side\Dispatcher::dispatch_commerce_event( $payload );
		if ( $expect !== ( count( $GLOBALS['sent'] ) > 0 ) ) {
			$failures[] = $label . ': expected ' . ( $expect ? 'sent' : 'not sent' );
		}
	}

	if ( $failures ) {
		echo implode( "\n", $failures ), "\n";
		exit( 1 );
	}
	echo "consent context checks passed\n";
}
