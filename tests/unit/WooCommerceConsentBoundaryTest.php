<?php
/**
 * Consent boundaries for WooCommerce and form attribution persistence.
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
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-woocommerce.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-fluent-forms-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-gravity-forms-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-ninja-forms-submission-extra-handler.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-ninja-forms-adapter.php';

	use CLICUTCL\Integrations\Forms\Abstract_Form_Adapter;
	use CLICUTCL\Integrations\Forms\Ninja_Forms_Adapter;
	use CLICUTCL\Integrations\Forms\Fluent_Forms_Adapter;
	use CLICUTCL\Integrations\Forms\Gravity_Forms_Adapter;
	use CLICUTCL\Integrations\WooCommerce;
	use PHPUnit\Framework\TestCase;

	/**
	 * Class WooCommerceConsentBoundaryTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class WooCommerceConsentBoundaryTest extends TestCase {
		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array();
			$_POST   = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
			$GLOBALS['clicktrail_test_wp_verify_nonce']     = false;
			$GLOBALS['clicktrail_test_wpfluent_called']    = false;
		}

		protected function tearDown(): void {
			$_COOKIE = array();
			$_POST   = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
			$GLOBALS['clicktrail_test_wp_verify_nonce']     = false;
			$GLOBALS['clicktrail_test_wpfluent_called']    = false;
		}

		public function test_denied_checkout_keeps_consent_snapshot_but_drops_posted_attribution(): void {
			$_COOKIE['production_consent'] = 'denied';
			$_POST = array(
				'_wpnonce'       => 'valid',
				'ct_ft_source'   => 'meta',
				'ct_ft_campaign' => 'launch',
				'ct_fbclid'      => 'opaque-click-id',
			);
			$GLOBALS['clicktrail_test_wp_verify_nonce'] = true;

			$order = $this->make_order();
			( new WooCommerce() )->save_order_attribution( $order );

			$this->assertArrayHasKey( WooCommerce::CONSENT_META_KEY, $order->meta );
			$attribution_keys = array_values(
				array_filter(
					array_keys( $order->meta ),
					static function ( $key ): bool {
						return 0 === strpos( (string) $key, '_clicutcl_' ) && WooCommerce::CONSENT_META_KEY !== $key;
					}
				)
			);

			$this->assertSame( array(), $attribution_keys );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_actions'] );
		}

		public function test_granted_checkout_persists_distinct_first_and_last_touch_from_nonce_verified_post(): void {
			$_COOKIE['production_consent'] = 'granted';
			$_POST = array(
				'_wpnonce'       => 'valid',
				'ct_ft_source'   => 'meta',
				'ct_ft_medium'   => 'paid_social',
				'ct_ft_campaign' => 'launch',
				'ct_lt_source'   => 'landing',
				'ct_lt_medium'   => 'referral',
				'ct_fbclid'      => 'opaque-click-id',
				'ct_trail_id'    => 'trl_test',
			);
			$GLOBALS['clicktrail_test_wp_verify_nonce'] = true;

			$order = $this->make_order();
			( new WooCommerce() )->save_order_attribution( $order );

			$this->assertSame( 'meta', $order->meta['_clicutcl_ft_source'] );
			$this->assertSame( 'paid_social', $order->meta['_clicutcl_ft_medium'] );
			$this->assertSame( 'launch', $order->meta['_clicutcl_ft_campaign'] );
			$this->assertSame( 'landing', $order->meta['_clicutcl_lt_source'] );
			$this->assertSame( 'referral', $order->meta['_clicutcl_lt_medium'] );
			$this->assertSame( 'opaque-click-id', $order->meta['_clicutcl_fbclid'] );
			$this->assertSame( 'trl_test', $order->meta['_clicutcl_trail_id'] );
			$this->assertSame( 'clicutcl_order_attribution_saved', $GLOBALS['clicktrail_test_actions'][0]['tag'] );
			$this->assertSame( $order, $GLOBALS['clicktrail_test_actions'][0]['args'][0] );
		}

		public function test_consent_permitted_cookie_payload_wins_over_conflicting_nonce_verified_post(): void {
			$_COOKIE['production_consent'] = 'granted';
			$_COOKIE['ct_attribution']    = json_encode(
				array(
					'ft_source'   => 'cookie-source',
					'ft_campaign' => 'cookie-campaign',
					'fbclid'      => 'cookie-click-id',
				)
			);
			$_POST = array(
				'_wpnonce'       => 'valid',
				'ct_ft_source'   => 'posted-source',
				'ct_ft_campaign' => 'posted-campaign',
				'ct_fbclid'      => 'posted-click-id',
			);
			$GLOBALS['clicktrail_test_wp_verify_nonce'] = true;

			$order = $this->make_order();
			( new WooCommerce() )->save_order_attribution( $order );

			$this->assertSame( 'cookie-source', $order->meta['_clicutcl_ft_source'] );
			$this->assertSame( 'cookie-campaign', $order->meta['_clicutcl_ft_campaign'] );
			$this->assertSame( 'cookie-click-id', $order->meta['_clicutcl_fbclid'] );
		}

		public function test_invalid_checkout_nonce_does_not_persist_posted_attribution(): void {
			$_COOKIE['production_consent'] = 'granted';
			$_POST = array(
				'_wpnonce'     => 'invalid',
				'ct_ft_source' => 'meta',
				'ct_fbclid'    => 'opaque-click-id',
			);

			$order = $this->make_order();
			( new WooCommerce() )->save_order_attribution( $order );

			$this->assertSame( array( WooCommerce::CONSENT_META_KEY => $order->meta[ WooCommerce::CONSENT_META_KEY ] ), $order->meta );
			$this->assertSame( array(), $GLOBALS['clicktrail_test_actions'] );
		}

		public function test_shared_form_logger_drops_attribution_when_consent_is_denied(): void {
			$_COOKIE['production_consent'] = 'denied';
			$adapter = new class extends Abstract_Form_Adapter {
				public function is_active() { return true; }
				public function get_platform_name() { return 'test'; }
				public function register_hooks() {}
				public function populate_fields( $form_or_context ) { return $form_or_context; }
				public function on_submission( $arg1, $arg2 ) {}
				public function record( array $attribution ) { $this->log_submission( 'test', 1, $attribution ); }
			};

			$adapter->record( array( 'fbclid' => 'stale-click-id' ) );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_actions'] );
		}

		public function test_fluent_submission_does_not_write_provider_meta_after_consent_withdrawal(): void {
			$_COOKIE['production_consent'] = 'denied';
			$adapter = new Fluent_Forms_Adapter();
			$adapter->on_submission( 42, array( 'ct_ft_source' => 'meta' ), null );

			$this->assertFalse( $GLOBALS['clicktrail_test_wpfluent_called'] );
		}

		public function test_gravity_entry_edit_does_not_restore_attribution_after_consent_withdrawal(): void {
			$_COOKIE['production_consent'] = 'denied';
			$adapter = new Gravity_Forms_Adapter();
			$adapter->restore_tracking_meta_after_edit( array(), 7, array( 'ct_ft_source' => 'meta' ) );

			$this->assertSame( array(), $GLOBALS['clicktrail_test_actions'] );
		}

		public function test_ninja_submission_filter_removes_stale_clicktrail_extra_but_preserves_other_extras(): void {
			$_COOKIE['production_consent'] = 'denied';
			$adapter = new Ninja_Forms_Adapter();
			$result  = $adapter->inject_attribution(
				array(
					'extra' => array(
						'clicktrail_attribution' => array( 'fbclid' => 'stale-click-id' ),
						'other'                => 'preserve-me',
					),
				)
			);

			$this->assertArrayHasKey( 'other', $result['extra'] );
			$this->assertArrayNotHasKey( 'clicktrail_attribution', $result['extra'] );
		}

		/**
		 * Create a minimal WooCommerce order double.
		 *
		 * @return object
		 */
		private function make_order(): object {
			return new class {
				/**
				 * Captured order metadata.
				 *
				 * @var array<string,mixed>
				 */
				public array $meta = array();

				/**
				 * Capture an order metadata update.
				 *
				 * @param string $key   Metadata key.
				 * @param mixed  $value Metadata value.
				 * @return void
				 */
				public function update_meta_data( $key, $value ): void {
					$this->meta[ (string) $key ] = $value;
				}
			};
		}
	}

	if ( ! function_exists( 'wpFluent' ) ) {
		/**
		 * Mark unexpected Fluent metadata writes during unit tests.
		 *
		 * @return object
		 */
		function wpFluent() {
			$GLOBALS['clicktrail_test_wpfluent_called'] = true;
			return new class {
				public function table( $table ) { return $this; }
				public function insert( $data ) { return true; }
			};
		}
	}
}
