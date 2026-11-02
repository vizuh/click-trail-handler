<?php
/**
 * Registered-user attribution metadata privacy lifecycle (#174).
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-ultimate-member.php';
	require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-user-registration-privacy.php';
	require_once dirname( __DIR__, 2 ) . '/includes/privacy/class-privacy-handler.php';
	require_once dirname( __DIR__ ) . '/helpers/user-meta-stubs.php';

	use CLICUTCL\Privacy\Privacy_Handler;
	use CLICUTCL\Privacy\User_Registration_Privacy;
	use PHPUnit\Framework\TestCase;

	/**
	 * Class UserRegistrationPrivacyTest
	 */
	final class UserRegistrationPrivacyTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['clicktrail_test_users']          = array(
				7 => (object) array(
					'ID'         => 7,
					'user_email' => 'member@example.test',
				),
				8 => (object) array(
					'ID'         => 8,
					'user_email' => 'other@example.test',
				),
			);
			$GLOBALS['clicktrail_test_user_meta']      = array(
				7 => array(
					'_clicutcl_gclid'          => 'GCLID-1',
					'_clicutcl_lt_source'      => 'test',
					'_clicutcl_um_signup_sent' => 'yes',
					'_clicutcl_other_plugin'   => 'lookalike',
					'first_name'               => 'Member',
				),
				8 => array(
					'_clicutcl_gclid' => 'GCLID-2',
				),
			);
			$GLOBALS['clicktrail_test_undeletable']    = array();
			$GLOBALS['clicktrail_test_get_users_args'] = array();
		}

		/**
		 * @dataProvider managedKeyProvider
		 */
		public function test_managed_key_allowlist( string $key, bool $expected ): void {
			$this->assertSame( $expected, User_Registration_Privacy::is_managed_meta_key( $key ) );
		}

		/**
		 * @return array<string,array{string,bool}>
		 */
		public static function managedKeyProvider(): array {
			return array(
				'attribution mapping field' => array( '_clicutcl_gclid', true ),
				'first touch field'         => array( '_clicutcl_ft_source', true ),
				'last touch field'          => array( '_clicutcl_lt_campaign', true ),
				'visitor id'                => array( '_clicutcl_visitor_id', true ),
				'sent marker'               => array( '_clicutcl_um_signup_sent', true ),
				'lookalike key'             => array( '_clicutcl_other_plugin', false ),
				'unprefixed lookalike'      => array( 'clicutcl_gclid', false ),
				'core meta'                 => array( 'first_name', false ),
			);
		}

		public function test_every_key_the_integration_can_write_is_managed(): void {
			foreach ( \CLICUTCL\Core\Attribution_Provider::get_field_mapping() as $field ) {
				$this->assertTrue( User_Registration_Privacy::is_managed_meta_key( '_clicutcl_' . $field ), $field );
			}
		}

		public function test_managed_meta_query_is_one_anchored_expression_over_the_exact_keys(): void {
			$query = User_Registration_Privacy::managed_meta_query();

			$this->assertCount( 1, $query );
			$this->assertSame( 'REGEXP', $query[0]['compare_key'] );
			$this->assertSame( 1, preg_match( '/^\^\(.+\)\$$/', $query[0]['key'] ) );
			$this->assertStringContainsString( '_clicutcl_gclid', $query[0]['key'] );
			$this->assertStringContainsString( '_clicutcl_um_signup_sent', $query[0]['key'] );
			$this->assertSame( 1, preg_match( '/^[\^\(\)\$\|a-z0-9_]+$/', $query[0]['key'] ), 'only characters that need no regexp escaping' );
		}

		public function test_export_returns_only_managed_meta_of_the_matching_user(): void {
			$result = ( new User_Registration_Privacy() )->export_user_data( 'member@example.test' );

			$this->assertTrue( $result['done'] );
			$this->assertCount( 3, $result['items'] );
			$this->assertSame( 'clicutcl_user_registration', $result['items'][0]['group_id'] );
			$blob = serialize( $result );
			$this->assertStringContainsString( 'GCLID-1', $blob );
			$this->assertStringNotContainsString( 'lookalike', $blob );
			$this->assertStringNotContainsString( 'Member', str_replace( 'ClickTrail Registration', '', $blob ), 'core profile meta is not exported' );
			$this->assertStringNotContainsString( 'GCLID-2', $blob );
		}

		public function test_export_for_an_unknown_email_or_a_later_page_is_empty_and_done(): void {
			$privacy = new User_Registration_Privacy();

			$this->assertSame( array( 'items' => array(), 'done' => true ), $privacy->export_user_data( 'nobody@example.test' ) );
			$this->assertSame( array( 'items' => array(), 'done' => true ), $privacy->export_user_data( 'member@example.test', 2 ) );
		}

		public function test_erase_removes_managed_meta_and_keeps_everything_else(): void {
			$result = ( new User_Registration_Privacy() )->erase_user_data( 'member@example.test' );

			$this->assertTrue( $result['items_removed'] );
			$this->assertFalse( $result['items_retained'] );
			$this->assertTrue( $result['done'] );
			$this->assertSame(
				array(
					'_clicutcl_other_plugin' => 'lookalike',
					'first_name'             => 'Member',
				),
				$GLOBALS['clicktrail_test_user_meta'][7]
			);
			$this->assertSame( array( '_clicutcl_gclid' => 'GCLID-2' ), $GLOBALS['clicktrail_test_user_meta'][8], 'another user is untouched' );
		}

		public function test_erase_reports_retained_when_a_key_cannot_be_deleted(): void {
			$GLOBALS['clicktrail_test_undeletable'] = array( '_clicutcl_gclid' );

			$result = ( new User_Registration_Privacy() )->erase_user_data( 'member@example.test' );

			$this->assertTrue( $result['items_retained'] );
			$this->assertNotEmpty( $result['messages'] );
		}

		public function test_erase_for_an_unknown_email_removes_nothing(): void {
			$result = ( new User_Registration_Privacy() )->erase_user_data( 'nobody@example.test' );

			$this->assertFalse( $result['items_removed'] );
			$this->assertFalse( $result['items_retained'] );
			$this->assertTrue( $result['done'] );
		}

		public function test_purge_all_removes_every_users_managed_meta_in_pages(): void {
			$result = ( new User_Registration_Privacy() )->purge_all_user_metadata( 1 );

			$this->assertSame( 2, $result['removed'] );
			$this->assertSame( 0, $result['failed'] );
			$this->assertFalse( $result['remaining'] );
			$this->assertArrayNotHasKey( '_clicutcl_gclid', $GLOBALS['clicktrail_test_user_meta'][7] );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_user_meta'][8] );
			$this->assertSame( 'lookalike', $GLOBALS['clicktrail_test_user_meta'][7]['_clicutcl_other_plugin'] );
		}

		public function test_purge_all_does_not_loop_on_a_user_that_keeps_meta(): void {
			$GLOBALS['clicktrail_test_undeletable'] = array( '_clicutcl_gclid' );

			$result = ( new User_Registration_Privacy() )->purge_all_user_metadata( 1 );

			$this->assertSame( 2, $result['failed'] );
			$this->assertTrue( $result['remaining'] );
		}

		public function test_purge_expired_selects_users_registered_before_the_cutoff_and_is_bounded(): void {
			$result = ( new User_Registration_Privacy() )->purge_expired_user_metadata( 30, 1 );

			$args = $GLOBALS['clicktrail_test_get_users_args'][0];
			$this->assertSame( 2, $args['number'], 'batch + 1 to detect a remainder' );
			$this->assertSame( 'user_registered', $args['date_query'][0]['column'] );
			$this->assertEqualsWithDelta( time() - 30 * DAY_IN_SECONDS, strtotime( $args['date_query'][0]['before'] . ' UTC' ), 5 );
			$this->assertSame( 1, $result['processed'] );
			$this->assertSame( 1, $result['removed'] );
			$this->assertTrue( $result['remaining'], 'the second user is left for the next run' );
		}

		public function test_privacy_handler_registers_a_separate_registration_exporter_and_eraser(): void {
			$handler   = new Privacy_Handler();
			$exporters = $handler->register_exporter( array() );
			$erasers   = $handler->register_eraser( array() );

			$this->assertArrayHasKey( 'clicutcl-user-registration', $exporters );
			$this->assertArrayHasKey( 'clicutcl-user-registration', $erasers );

			$exported = call_user_func( $exporters['clicutcl-user-registration']['callback'], 'member@example.test', 1 );
			$this->assertTrue( $exported['done'] );
			$this->assertCount( 3, $exported['data'] );

			$erased = call_user_func( $erasers['clicutcl-user-registration']['callback'], 'member@example.test', 1 );
			$this->assertTrue( $erased['items_removed'] );
			$this->assertTrue( $erased['done'] );
			$this->assertArrayNotHasKey( '_clicutcl_gclid', $GLOBALS['clicktrail_test_user_meta'][7] );
		}
	}
}
