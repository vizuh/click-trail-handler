<?php
/** Isolated queue processing regression; run: php tests/queue-processing-check.php */
namespace CLICUTCL\Modules\Consent_Mode {
	class Consent_Mode_Settings {
		public function is_consent_mode_enabled() { return true; }
		public function get_mode() { return $GLOBALS['mode']; }
		public function is_consent_required_for_request() { return false; } // Worker geography must not decide.
		public function get_cookie_name() { return 'ct_consent'; }
	}
}
namespace CLICUTCL\Server_Side {
	class Settings {
		public static function get() { return array( 'enabled' => true, 'endpoint_url' => 'https://collector.test', 'adapter' => 'generic' ); }
	}
}
namespace CLICUTCL\Tracking {
	class Dedup_Store {
		public static function is_duplicate( ...$args ) { return false; }
		public static function mark( ...$args ) {}
	}
}
namespace CLICUTCL\Database {
	class Installer { const DB_VERSION_OPTION = 'db_version'; }
	class Touch_Events_Store { public static function record( ...$args ) {} }
}
namespace {
	function apply_filters( $tag, $value, ...$args ) {
		if ( 'clicutcl_queue_consent_snapshot' === $tag ) {
			$subject = $args[0]['meta']['session_id'] ?? '';
			return $GLOBALS['authority'][ $subject ] ?? null;
		}
		if ( 'clicutcl_dispatch_in_environment' === $tag ) { return $GLOBALS['allow_environment']; }
		return $value;
	}
	function get_option( $key, $default = false ) { return 'db_version' === $key ? 2 : $default; }
	require __DIR__ . '/bootstrap.php';
	function esc_url_raw( $url ) { return $url; }
	function wp_get_environment_type() { return $GLOBALS['environment']; }
	function get_transient( $key ) { return false; }
	function set_transient( ...$args ) { return true; }
	function wp_remote_post( $url, $args ) { ++$GLOBALS['sends']; return array(); }
	function wp_remote_retrieve_response_code( $response ) { return 200; }
	function wp_remote_retrieve_body( $response ) { return ''; }
	function check( $condition, $message ) { if ( ! $condition ) { throw new \RuntimeException( $message ); } }
	foreach ( array(
		'Consent/class-decision-v1.php', 'Consent/class-resolver-v1.php', 'Consent/class-snapshot-v1.php',
		'server-side/class-consent.php', 'server-side/class-event.php', 'server-side/class-adapter-interface.php',
		'server-side/class-adapter-result.php', 'server-side/class-generic-collector-adapter.php',
		'support/class-feature-registry.php', 'server-side/class-dispatcher.php', 'server-side/class-queue.php',
	) as $file ) { require dirname( __DIR__ ) . '/includes/' . $file; }
	$wpdb = new class {
		public $prefix = 'wp_';
		public $updated = array();
		public $deleted = false;
		public function update( $table, $data, ...$args ) { $this->updated = $data; return 1; }
		public function delete( ...$args ) { $this->deleted = true; return 1; }
	};
	$grant = \CLICUTCL\Consent\Snapshot_V1::capture( array( 'marketing' => true ), true );
	$denial = \CLICUTCL\Consent\Snapshot_V1::capture( array( 'marketing' => false ), true );
	$process = new \ReflectionMethod( \CLICUTCL\Server_Side\Queue::class, 'process_row' );
	$process->setAccessible( true );
	$environment = 'production';
	$allow_environment = false;
	$mode = 'strict';
	$payload = array( 'event_name' => 'lead', 'event_id' => 'queued-1', 'consent' => $grant, 'meta' => array( 'session_id' => 'buyer-a' ) );
	$row = array( 'id' => 1, 'payload' => json_encode( $payload ), 'endpoint' => 'https://collector.test', 'adapter' => 'generic', 'attempts' => 2 );
	$run = function () use ( $process, $row, $wpdb ) {
		$GLOBALS['sends'] = 0;
		$wpdb->updated = array();
		$wpdb->deleted = false;
		$process->invoke( null, $row );
	};

	$_COOKIE = array();
	$authority = array( 'buyer-a' => $grant );
	$run();
	check( 1 === $sends && $wpdb->deleted, 'Cookie-free subject grant must deliver.' );

	$_COOKIE['ct_consent'] = json_encode( array( 'marketing' => false ) );
	$run();
	check( 1 === $sends, 'Another request denial must not override subject grant.' );

	$_COOKIE['ct_consent'] = json_encode( array( 'marketing' => true ) );
	$authority = array( 'buyer-a' => $denial, 'buyer-b' => $grant );
	$run();
	check( 0 === $sends && 'failed' === ( $wpdb->updated['status'] ?? '' ), 'Subject withdrawal must block despite other grant.' );

	foreach ( array( null, array( 'decision' => 'maybe' ), array_merge( $grant, array( 'expires_at' => time() - 1 ) ) ) as $unknown ) {
		$authority = array( 'buyer-a' => $unknown, 'buyer-b' => $grant );
		$run();
		check( 0 === $sends && ! $wpdb->deleted, 'Unknown or expired subject authority must not send/delete.' );
		check( 'consent_unresolved' === ( $wpdb->updated['last_error'] ?? '' ), 'Unknown authority must be observable.' );
		check( ! array_key_exists( 'attempts', $wpdb->updated ) && ! array_key_exists( 'status', $wpdb->updated ), 'Deferral must preserve pending state and attempts.' );
		check( strtotime( $wpdb->updated['next_attempt_at'] . ' UTC' ) > time(), 'Deferral must advance scheduling.' );
	}

	$mode = 'geo';
	$run();
	check( 0 === $sends, 'Worker geography must not bypass subject consent.' );
	$mode = 'relaxed';
	$run();
	check( 1 === $sends, 'Relaxed policy may retry without a consent registry.' );

	foreach ( array( 'local', 'development' ) as $environment ) {
		$run();
		check( 0 === $sends && array() === $wpdb->updated && ! $wpdb->deleted, 'Development retry must leave queue untouched.' );
		$result = \CLICUTCL\Server_Side\Dispatcher::dispatch( new \CLICUTCL\Server_Side\Event( $payload ) );
		check( $result->skipped && 'non_production_environment' === $result->message, 'Initial dispatch must use same environment guard.' );
	}
	$allow_environment = true;
	$run();
	check( 1 === $sends, 'Explicit environment override must cover retry.' );
	echo "queue processing checks passed\n";
}
