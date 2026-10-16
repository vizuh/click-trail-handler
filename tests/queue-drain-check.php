<?php
/** Isolated queue drain regression: one run drains a backlog beyond one batch; run: php tests/queue-drain-check.php */
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
	if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
	if ( ! function_exists( 'current_time' ) ) { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
	if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( ...$args ) { return true; } }
	$wpdb = new class {
		public $prefix  = 'wp_';
		public $options = 'wp_options';
		public $pending = array();
		public $selects = 0;
		public function prepare( $query, ...$args ) { return $query; }
		public function query( $query ) { return 1; }
		public function get_var( $query ) { return null; }
		public function get_results( $query, $output = null ) {
			++$this->selects;
			return array_slice( array_values( $this->pending ), 0, 10 );
		}
		public function update( $table, $data, $where = array(), ...$args ) { return 1; }
		public function delete( $table, $where = array(), ...$args ) {
			if ( isset( $where['id'] ) ) {
				unset( $this->pending[ (int) $where['id'] ] );
			}
			return 1;
		}
	};
	$environment       = 'production';
	$allow_environment = false;
	$mode              = 'relaxed';
	$authority         = array();
	$_COOKIE           = array();
	$memo              = new \ReflectionProperty( \CLICUTCL\Server_Side\Queue::class, 'table_exists_mem' );
	$memo->setAccessible( true );
	$memo->setValue( null, true );

	$seed = function ( int $count ) use ( $wpdb ) {
		$wpdb->pending = array();
		for ( $id = 1; $id <= $count; $id++ ) {
			$payload               = array( 'event_name' => 'lead', 'event_id' => 'queued-' . $id );
			$wpdb->pending[ $id ] = array( 'id' => $id, 'payload' => json_encode( $payload ), 'endpoint' => 'https://collector.test', 'adapter' => 'generic', 'attempts' => 1 );
		}
		$wpdb->selects    = 0;
		$GLOBALS['sends'] = 0;
	};

	$seed( 25 );
	\CLICUTCL\Server_Side\Queue::process();
	check( 25 === $sends && array() === $wpdb->pending, 'One run must drain a backlog larger than one batch; sent ' . $sends );

	// Rows that stay due after processing (non-production leaves them untouched) must not spin.
	$environment = 'local';
	$seed( 25 );
	\CLICUTCL\Server_Side\Queue::process();
	check( 0 === $sends && $wpdb->selects <= 2, 'Untouched rows must end the run, not loop; selects ' . $wpdb->selects );
	echo "queue drain checks passed\n";
}
