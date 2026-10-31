<?php
/**
 * Abstract_Form_Adapter::resolve_submitted_attribution() and get_render_payload() (#152).
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';

	use CLICUTCL\Integrations\Forms\Abstract_Form_Adapter;
	use PHPUnit\Framework\TestCase;

	/**
	 * Exposes the protected shared rule.
	 */
	final class ClickTrailResolverAdapter extends Abstract_Form_Adapter {
		public function is_active() {
			return true;
		}

		public function get_platform_name() {
			return 'Resolver Test';
		}

		public function register_hooks() {}

		public function on_submission( $arg1, $arg2 ) {}

		public function populate_fields( $form_or_context ) {
			return $form_or_context;
		}

		public function resolve( array $posted, $marker ) {
			return $this->resolve_submitted_attribution( $posted, $marker );
		}

		public function render() {
			return $this->get_render_payload();
		}
	}

	/**
	 * Class SubmittedAttributionResolverTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class SubmittedAttributionResolverTest extends TestCase {
		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array( 'attribution' => json_encode( array( 'gclid' => 'B1' ) ) );
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_filters']              = array();
		}

		private static function posted_by_a(): array {
			return array( 'gclid' => 'A1', 'ft_source' => 'A2', 'lt_source' => 'A3' );
		}

		public function test_cookie_is_authoritative_and_absent_keys_are_blank(): void {
			$out = ( new ClickTrailResolverAdapter() )->resolve( self::posted_by_a(), true );

			$this->assertSame( 'B1', $out['gclid'] );
			$this->assertSame( '', $out['ft_source'] );
			$this->assertSame( '', $out['lt_source'] );
		}

		public function test_no_cookie_with_marker_keeps_posted_values(): void {
			$_COOKIE = array();
			$out     = ( new ClickTrailResolverAdapter() )->resolve( self::posted_by_a(), true );

			$this->assertSame( 'A1', $out['gclid'] );
			$this->assertSame( 'A2', $out['ft_source'] );
		}

		public function test_no_cookie_without_marker_blanks_everything(): void {
			$_COOKIE = array();
			$out     = ( new ClickTrailResolverAdapter() )->resolve( self::posted_by_a(), false );

			$this->assertSame( '', implode( '', $out ) );
			$this->assertArrayHasKey( 'gclid', $out );
		}

		public function test_consent_required_and_absent_blanks_everything_even_with_cookie_and_marker(): void {
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$_COOKIE['production_consent']                   = 'denied';
			$out = ( new ClickTrailResolverAdapter() )->resolve( self::posted_by_a(), true );

			$this->assertSame( '', implode( '', $out ) );
		}

		public function test_malformed_cookie_counts_as_absent(): void {
			$_COOKIE = array( 'attribution' => '{broken' );

			$this->assertSame( 'A1', ( new ClickTrailResolverAdapter() )->resolve( self::posted_by_a(), true )['gclid'] );
			$this->assertSame( '', ( new ClickTrailResolverAdapter() )->resolve( self::posted_by_a(), false )['gclid'] );
		}

		public function test_render_payload_is_empty_by_default_and_the_cookie_when_the_filter_opts_in(): void {
			$adapter = new ClickTrailResolverAdapter();
			$this->assertSame( '', implode( '', $adapter->render() ) );

			$GLOBALS['clicktrail_test_filters']['clicutcl_render_server_attribution_values'] = static fn( $render, $platform ) => 'Resolver Test' === $platform;
			$this->assertSame( 'B1', $adapter->render()['gclid'] );
		}
	}
}
