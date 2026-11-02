<?php
/**
 * WhatsApp reference codes (specs/008-whatsapp-handoff, #145): the create route's gates and
 * validation, the stored snapshot and TTL, collision handling, the admin-only lookup (404 for
 * unknown and expired codes), the purge, and the eraser's visitor-ID path.
 *
 * `$wpdb` is a small in-memory fake that understands only the statements the store issues.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
	require_once dirname( __DIR__, 2 ) . '/includes/settings/class-attribution-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/support/class-feature-registry.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-auth.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-webhook-auth.php';
	require_once dirname( __DIR__, 2 ) . '/includes/database/class-whatsapp-ref-store.php';
	require_once dirname( __DIR__, 2 ) . '/includes/api/class-tracking-controller.php';

	use CLICUTCL\Api\Tracking_Controller;
	use CLICUTCL\Core\Storage\Option_Cache;
	use CLICUTCL\Database\Whatsapp_Ref_Store;
	use CLICUTCL\Tracking\Auth;
	use PHPUnit\Framework\TestCase;

	/**
	 * In-memory `$wpdb` for the reference table.
	 */
	final class Fake_Whatsapp_Wpdb {
		public string $prefix = 'wp_';
		public bool $table_exists = true;
		/** @var array<string,array<string,string>> */
		public array $rows = array();

		public function prepare( string $query, ...$args ): array {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array(
				'sql'  => $query,
				'args' => $args,
			);
		}

		public function get_var( array $q ) {
			return 0 === strpos( $q['sql'], 'SHOW TABLES LIKE' ) && $this->table_exists ? $q['args'][0] : null;
		}

		public function query( array $q ) {
			$sql  = $q['sql'];
			$args = $q['args'];
			if ( 0 === strpos( $sql, 'INSERT INTO' ) ) {
				// Column order of Whatsapp_Ref_Store::build_row().
				$row = array_combine( array( 'blog_id', 'code', 'visitor_id', 'selected_click_id_key', 'selected_click_id', 'snapshot', 'created_at', 'expires_at' ), array_map( 'strval', $args ) );
				$key = $row['blog_id'] . ':' . $row['code'];
				if ( isset( $this->rows[ $key ] ) ) {
					return 0;
				}
				$this->rows[ $key ] = $row;
				return 1;
			}
			if ( 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, 'visitor_id IN' ) ) {
				$deleted = 0;
				foreach ( $this->rows as $key => $row ) {
					if ( in_array( $row['visitor_id'], $args, true ) ) {
						unset( $this->rows[ $key ] );
						++$deleted;
					}
				}
				return $deleted;
			}
			if ( 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, 'expires_at <' ) ) {
				$deleted = 0;
				foreach ( $this->rows as $key => $row ) {
					if ( $row['expires_at'] < $args[0] || $row['created_at'] < $args[1] ) {
						unset( $this->rows[ $key ] );
						++$deleted;
					}
				}
				return $deleted;
			}
			return false;
		}

		public function get_row( array $q, $output = null ) {
			foreach ( $this->rows as $row ) {
				if ( (string) $row['blog_id'] === (string) $q['args'][0] && $row['code'] === $q['args'][1] && $row['expires_at'] > $q['args'][2] ) {
					return $row;
				}
			}
			return null;
		}
	}

	/**
	 * Class WhatsappRefTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class WhatsappRefTest extends TestCase {
		private Fake_Whatsapp_Wpdb $db;

		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$this->db                                    = new Fake_Whatsapp_Wpdb();
			$GLOBALS['wpdb']                             = $this->db;
			$_COOKIE                                     = array();
			$GLOBALS['clicktrail_test_transients']       = array();
			$GLOBALS['clicktrail_test_filters']          = array();
			$GLOBALS['clicktrail_test_current_user_can'] = false;
			$GLOBALS['clicktrail_test_consent_required'] = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			Option_Cache::set( 'clicutcl_tracking_v2', array() );
			$this->settings( array() );
		}

		/**
		 * Attribution settings with the append option on unless overridden.
		 *
		 * @param array $overrides Overrides.
		 */
		private function settings( array $overrides ): void {
			Option_Cache::set(
				'clicutcl_attribution_settings',
				array_merge(
					array(
						'enable_whatsapp'             => 1,
						'whatsapp_append_attribution' => 1,
						'cookie_days'                 => 90,
					),
					$overrides
				)
			);
		}

		private function body( array $overrides = array() ): array {
			return array_merge(
				array(
					'code'                  => 'ABCD2345',
					'visitor_id'            => 'vis_123',
					'selected_click_id_key' => 'gclid',
					'data'                  => array(
						'gclid'      => 'G1',
						'ft_source'  => 'google',
						'ft_medium'  => 'cpc',
						'lt_source'  => 'newsletter',
						'lt_medium'  => 'email',
						'ignored'    => 'not-allowlisted',
					),
				),
				$overrides
			);
		}

		private function request( array $body, ?string $token = null, string $route = '/clicutcl/v2/whatsapp-ref' ): \WP_REST_Request {
			$token   = $token ?? Auth::mint_client_token();
			$payload = json_encode( $body );
			return new \WP_REST_Request( array( 'X-Clicutcl-Token' => $token ), (string) $payload, $route );
		}

		private function create( array $body ) {
			$controller = new Tracking_Controller();
			return $controller->create_whatsapp_ref( $this->request( $body ) );
		}

		// ----- Create route: permission gates -----

		public function test_create_rejects_when_the_append_setting_is_off(): void {
			$this->settings( array( 'whatsapp_append_attribution' => 0 ) );
			$result = ( new Tracking_Controller() )->whatsapp_ref_create_permissions_check( $this->request( $this->body() ) );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'whatsapp_ref_disabled', $result->get_error_code() );
		}

		public function test_create_rejects_when_whatsapp_tracking_is_off(): void {
			$this->settings( array( 'enable_whatsapp' => 0 ) );
			$result = ( new Tracking_Controller() )->whatsapp_ref_create_permissions_check( $this->request( $this->body() ) );
			$this->assertSame( 'whatsapp_ref_disabled', $result->get_error_code() );
		}

		public function test_setting_defaults_to_off(): void {
			Option_Cache::set( 'clicutcl_attribution_settings', array() );
			$result = ( new Tracking_Controller() )->whatsapp_ref_create_permissions_check( $this->request( $this->body() ) );
			$this->assertSame( 'whatsapp_ref_disabled', $result->get_error_code() );
		}

		public function test_create_rejects_missing_and_bad_tokens(): void {
			$controller = new Tracking_Controller();
			$missing    = $controller->whatsapp_ref_create_permissions_check( new \WP_REST_Request( array(), (string) json_encode( $this->body() ), '/clicutcl/v2/whatsapp-ref' ) );
			$this->assertSame( 'invalid_token', $missing->get_error_code() );
			$bad = $controller->whatsapp_ref_create_permissions_check( $this->request( $this->body(), 'garbage' ) );
			$this->assertSame( 'invalid_token', $bad->get_error_code() );
		}

		public function test_create_rejects_an_oversized_body(): void {
			$result = ( new Tracking_Controller() )->whatsapp_ref_create_permissions_check( $this->request( $this->body( array( 'padding' => str_repeat( 'x', 17000 ) ) ) ) );
			$this->assertSame( 'payload_too_large', $result->get_error_code() );
		}

		public function test_create_is_rate_limited(): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_v2_rate_limit'] = static fn() => 1;
			$controller = new Tracking_Controller();
			$this->assertTrue( $controller->whatsapp_ref_create_permissions_check( $this->request( $this->body() ) ) );
			$second = $controller->whatsapp_ref_create_permissions_check( $this->request( $this->body() ) );
			$this->assertSame( 'rate_limited', $second->get_error_code() );
		}

		public function test_create_accepts_a_valid_token(): void {
			$this->assertTrue( ( new Tracking_Controller() )->whatsapp_ref_create_permissions_check( $this->request( $this->body() ) ) );
		}

		public function test_create_is_consent_gated_by_the_request_cookie_not_the_body(): void {
			$this->settings( array( 'require_consent' => 1 ) );
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$controller = new Tracking_Controller();

			$body   = $this->body( array( 'consent' => array( 'marketing' => true ) ) );
			$none   = $controller->whatsapp_ref_create_permissions_check( $this->request( $body ) );
			$this->assertSame( 'consent_required', $none->get_error_code(), 'no consent cookie' );

			$_COOKIE['production_consent'] = 'denied';
			$denied = $controller->whatsapp_ref_create_permissions_check( $this->request( $body ) );
			$this->assertSame( 'consent_required', $denied->get_error_code(), 'denied cookie wins over a body claim' );

			$_COOKIE['production_consent'] = 'granted';
			$this->assertTrue( $controller->whatsapp_ref_create_permissions_check( $this->request( $body ) ) );
		}

		// ----- Create route: validation, snapshot, TTL, collision -----

		public function test_create_stores_the_snapshot_with_ttl_equal_to_retention(): void {
			$this->settings( array( 'cookie_days' => 30 ) );
			$before = time();
			$result = $this->create( $this->body() );

			$this->assertIsArray( $result );
			$this->assertTrue( $result['success'] );
			$this->assertSame( 'CT-ABCD2345', $result['code'] );
			$row = $this->db->rows['1:ABCD2345'];
			$this->assertSame( 'vis_123', $row['visitor_id'] );
			$this->assertSame( 'gclid', $row['selected_click_id_key'] );
			$this->assertSame( 'G1', $row['selected_click_id'] );
			$snapshot = json_decode( $row['snapshot'], true );
			$this->assertSame( array( 'source' => 'google', 'medium' => 'cpc' ), $snapshot['first_touch'] );
			$this->assertSame( array( 'source' => 'newsletter', 'medium' => 'email' ), $snapshot['last_touch'] );
			$this->assertSame( array( 'gclid' => 'G1' ), $snapshot['click_ids'] );
			$this->assertStringNotContainsString( 'not-allowlisted', $row['snapshot'] );

			$created = strtotime( $row['created_at'] . ' UTC' );
			$expires = strtotime( $row['expires_at'] . ' UTC' );
			$this->assertEqualsWithDelta( $before, $created, 5 );
			$this->assertSame( 30 * DAY_IN_SECONDS, $expires - $created );
			$this->assertSame( $row['expires_at'], $result['expires_at'] );
		}

		public function test_ttl_defaults_to_ninety_days_and_a_selected_key_without_a_value_is_dropped(): void {
			Option_Cache::set( 'clicutcl_attribution_settings', array( 'enable_whatsapp' => 1, 'whatsapp_append_attribution' => 1 ) );
			$this->create( $this->body( array( 'selected_click_id_key' => 'fbclid' ) ) );
			$row = $this->db->rows['1:ABCD2345'];
			$this->assertSame( '', $row['selected_click_id_key'] );
			$this->assertSame( '', $row['selected_click_id'] );
			$this->assertSame( 90 * DAY_IN_SECONDS, strtotime( $row['expires_at'] . ' UTC' ) - strtotime( $row['created_at'] . ' UTC' ) );
		}

		public function test_create_rejects_malformed_codes_and_empty_attribution(): void {
			foreach ( array( '', 'abcd2345', 'ABCD1234', 'ABCDE', 'ABCD23456', 'CT-ABCD2345', "ABCD23\n5" ) as $bad ) {
				$result = $this->create( $this->body( array( 'code' => $bad ) ) );
				$this->assertTrue( is_wp_error( $result ), 'code ' . $bad );
				$this->assertSame( 'invalid_code', $result->get_error_code(), 'code ' . $bad );
			}

			$empty = $this->create( $this->body( array( 'data' => array( 'ignored' => 'x' ) ) ) );
			$this->assertSame( 'invalid_attribution_data', $empty->get_error_code() );
			$this->assertSame( array(), $this->db->rows );
		}

		public function test_a_repeated_code_is_a_collision_and_keeps_the_first_row(): void {
			$this->create( $this->body() );
			$again = $this->create( $this->body( array( 'visitor_id' => 'someone_else', 'data' => array( 'gclid' => 'G2' ) ) ) );
			$this->assertTrue( is_wp_error( $again ) );
			$this->assertSame( 'whatsapp_ref_collision', $again->get_error_code() );
			$this->assertSame( 'vis_123', $this->db->rows['1:ABCD2345']['visitor_id'] );
			$this->assertSame( 1, count( $this->db->rows ) );
		}

		public function test_a_missing_table_is_unavailable_not_a_fatal(): void {
			$this->db->table_exists = false;
			$result                 = $this->create( $this->body() );
			$this->assertSame( 'whatsapp_ref_unavailable', $result->get_error_code() );
		}

		public function test_visitor_id_is_capped(): void {
			$this->create( $this->body( array( 'visitor_id' => str_repeat( 'v', 200 ) ) ) );
			$this->assertSame( 64, strlen( $this->db->rows['1:ABCD2345']['visitor_id'] ) );
		}

		// ----- Lookup -----

		public function test_lookup_is_admin_only(): void {
			$controller = new Tracking_Controller();
			$request    = new \WP_REST_Request( array(), '', '/clicutcl/v2/whatsapp-ref/ABCD2345', array( 'code' => 'ABCD2345' ) );
			$this->assertFalse( $controller->diagnostics_permissions_check( $request ) );
			$GLOBALS['clicktrail_test_current_user_can'] = true;
			$this->assertTrue( $controller->diagnostics_permissions_check( $request ) );
		}

		public function test_lookup_returns_the_snapshot_for_any_spelling_of_the_code(): void {
			$this->create( $this->body() );
			$controller = new Tracking_Controller();
			foreach ( array( 'ABCD2345', 'ct-abcd2345', 'CT-ABCD2345', ' abcd2345 ' ) as $spelling ) {
				$result = $controller->lookup_whatsapp_ref( new \WP_REST_Request( array(), '', '/', array( 'code' => $spelling ) ) );
				$this->assertIsArray( $result, $spelling );
				$this->assertTrue( $result['success'] );
				$this->assertSame( 'CT-ABCD2345', $result['data']['code'] );
				$this->assertSame( 'vis_123', $result['data']['visitor_id'] );
				$this->assertSame( array( 'key' => 'gclid', 'value' => 'G1' ), $result['data']['selected_click_id'] );
				$this->assertSame( 'google', $result['data']['attribution']['first_touch']['source'] );
				$this->assertSame( 'email', $result['data']['attribution']['last_touch']['medium'] );
				$this->assertArrayHasKey( 'created_at', $result['data'] );
				$this->assertArrayHasKey( 'expires_at', $result['data'] );
			}
		}

		public function test_lookup_of_an_unknown_malformed_or_expired_code_is_404(): void {
			$this->create( $this->body() );
			$controller = new Tracking_Controller();
			foreach ( array( 'ZZZZ2222', 'nope', '' ) as $code ) {
				$result = $controller->lookup_whatsapp_ref( new \WP_REST_Request( array(), '', '/', array( 'code' => $code ) ) );
				$this->assertTrue( is_wp_error( $result ), $code );
				$this->assertSame( 'whatsapp_ref_not_found', $result->get_error_code(), $code );
			}

			$this->db->rows['1:ABCD2345']['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 5 );
			$expired = $controller->lookup_whatsapp_ref( new \WP_REST_Request( array(), '', '/', array( 'code' => 'CT-ABCD2345' ) ) );
			$this->assertSame( 'whatsapp_ref_not_found', $expired->get_error_code() );
		}

		// ----- Store: purge, eraser, builders -----

		public function test_purge_removes_expired_rows_and_rows_older_than_the_current_retention(): void {
			$now = 1_800_000_000;
			foreach ( array( 'AAAAAAAA' => array( -10, 80 ), 'BBBBBBBB' => array( 100, 5 ), 'CCCCCCCC' => array( 100, 40 ) ) as $code => $ttl ) {
				// Each entry: [expires in N days from now (negative = past), created N days ago].
				$this->db->rows[ '1:' . $code ] = array(
					'blog_id'    => '1',
					'code'       => $code,
					'visitor_id' => 'v',
					'created_at' => gmdate( 'Y-m-d H:i:s', $now - $ttl[1] * DAY_IN_SECONDS ),
					'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $ttl[0] * DAY_IN_SECONDS ),
				);
			}
			$deleted = Whatsapp_Ref_Store::purge_expired( 30, $now );
			$this->assertSame( 2, $deleted, 'AAAAAAAA expired, CCCCCCCC older than the 30 day retention' );
			$this->assertSame( array( '1:BBBBBBBB' ), array_keys( $this->db->rows ) );
		}

		public function test_visitor_ids_are_read_from_logged_event_payloads_and_the_eraser_deletes_their_rows(): void {
			$rows = array(
				array( 'event_data' => json_encode( array( 'email' => 'a@example.test', 'attribution' => array( 'visitor_id' => 'vis_123', 'gclid' => 'G1' ) ) ) ),
				array( 'event_data' => json_encode( array( 'visitor_id' => 'vis_456' ) ) ),
				array( 'event_data' => 'not json' ),
				array( 'event_data' => json_encode( array( 'attribution' => array( 'visitor_id' => '' ) ) ) ),
			);
			$ids = Whatsapp_Ref_Store::visitor_ids_from_event_rows( $rows );
			$this->assertSame( array( 'vis_123', 'vis_456' ), $ids );

			$this->create( $this->body() );
			$this->create( $this->body( array( 'code' => 'EFGH6767', 'visitor_id' => 'someone_else' ) ) );
			$this->assertSame( 1, Whatsapp_Ref_Store::delete_by_visitor_ids( $ids ) );
			$this->assertSame( array( '1:EFGH6767' ), array_keys( $this->db->rows ) );
			$this->assertSame( 0, Whatsapp_Ref_Store::delete_by_visitor_ids( array() ) );
		}

		public function test_normalize_code(): void {
			$this->assertSame( 'ABCD2345', Whatsapp_Ref_Store::normalize_code( 'ct-abcd2345' ) );
			$this->assertSame( 'ABCDEF', Whatsapp_Ref_Store::normalize_code( 'ABCDEF' ) );
			$this->assertSame( '', Whatsapp_Ref_Store::normalize_code( 'ABCDE' ) );
			$this->assertSame( '', Whatsapp_Ref_Store::normalize_code( 'ABCD1189' ) );
		}

		public function test_the_events_route_accepts_the_new_browser_event(): void {
			require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-canonicaleventinterfacev2.php';
			require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-eventv2.php';
			$this->assertTrue( \CLICUTCL\Tracking\EventV2::is_browser_event_allowed( 'contact_whatsapp_start' ) );
		}

		// ----- Lifecycle wiring (source-level: these paths need a live database) -----

		public function test_installer_cleanup_privacy_and_uninstall_cover_the_table(): void {
			$root = dirname( __DIR__, 2 );
			$this->assertStringContainsString( 'public const DB_VERSION = 5;', (string) file_get_contents( $root . '/includes/database/class-installer.php' ) );
			$this->assertStringContainsString( 'clicutcl_whatsapp_refs', (string) file_get_contents( $root . '/includes/database/class-installer.php' ) );
			$this->assertStringContainsString( 'Whatsapp_Ref_Store::purge_expired', (string) file_get_contents( $root . '/includes/utils/class-cleanup.php' ) );
			$this->assertStringContainsString( 'Whatsapp_Ref_Store::delete_by_visitor_ids', (string) file_get_contents( $root . '/includes/privacy/class-privacy-handler.php' ) );
			$this->assertStringContainsString( 'clicutcl_whatsapp_refs', (string) file_get_contents( $root . '/uninstall.php' ) );
			$this->assertStringContainsString( 'clicutcl_whatsapp_refs_table_ready', (string) file_get_contents( $root . '/uninstall.php' ) );
		}
	}
}
