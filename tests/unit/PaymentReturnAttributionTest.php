<?php
/**
 * Attribution across off-site payment redirects (issue #129).
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
	require_once dirname( __DIR__, 2 ) . '/includes/class-clicutcl-core.php';

	use CLICUTCL\Core\Attribution_Provider;
	use CLICUTCL\Integrations\WooCommerce;
	use CLICUTCL\Plugin;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return (string) $url;
		}
	}

	if ( ! function_exists( 'rest_url' ) ) {
		function rest_url( $path = '' ) {
			return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
		}
	}

	/**
	 * Class PaymentReturnAttributionTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class PaymentReturnAttributionTest extends TestCase {
		protected function setUp(): void {
			require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
			$_COOKIE = array(
				'production_consent' => 'granted',
				'ct_attribution'     => json_encode(
					array(
						'lt_source' => 'google',
						'lt_medium' => 'cpc',
						'gclid'     => 'paid-click-id',
					)
				),
			);
			$_POST                                           = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['clicktrail_test_filters']              = array();
			$GLOBALS['clicktrail_test_consent_required']     = true;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = true;
		}

		protected function tearDown(): void {
			$_COOKIE                                         = array();
			$GLOBALS['clicktrail_test_actions']              = array();
			$GLOBALS['clicktrail_test_filters']              = array();
			$GLOBALS['clicktrail_test_consent_required']     = false;
			$GLOBALS['clicktrail_test_consent_mode_enabled'] = false;
		}

		public function test_store_api_order_meta_is_saved_before_the_gateway_takes_over(): void {
			$order = $this->make_order( 42 );

			( new WooCommerce() )->save_store_api_order_attribution( $order );

			$this->assertCount( 1, $order->saved, 'Store API orders must be saved after attribution is written' );
			$this->assertSame( 'google', $order->saved[0]['_clicutcl_lt_source'] );
			$this->assertSame( 'cpc', $order->saved[0]['_clicutcl_lt_medium'] );
			$this->assertSame( 'paid-click-id', $order->saved[0]['_clicutcl_gclid'] );
			$this->assertArrayHasKey( WooCommerce::CONSENT_META_KEY, $order->saved[0] );
		}

		public function test_store_api_order_without_id_is_not_saved(): void {
			$order = $this->make_order( 0 );

			( new WooCommerce() )->save_store_api_order_attribution( $order );

			$this->assertSame( 'google', $order->meta['_clicutcl_lt_source'] );
			$this->assertSame( array(), $order->saved );
		}

		public function test_classic_checkout_hook_leaves_the_save_to_woocommerce(): void {
			$order = $this->make_order( 42 );

			( new WooCommerce() )->save_order_attribution( $order, array() );

			$this->assertSame( 'paid-click-id', $order->meta['_clicutcl_gclid'] );
			$this->assertSame( array(), $order->saved );
		}

		public function test_default_ignored_referrer_hosts_cover_hosted_payment_pages(): void {
			$hosts = Attribution_Provider::get_ignored_referrer_hosts();

			foreach ( array( 'paypal.com', 'stripe.com', 'mollie.com', 'klarna.com', 'squareup.com', 'adyen.com', 'checkout.com' ) as $host ) {
				$this->assertContains( $host, $hosts );
			}
		}

		public function test_filtered_ignored_referrer_hosts_are_normalized(): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_ignored_referrer_hosts'] = static function ( array $hosts ): array {
				$hosts[] = ' ACS.Issuer-Bank.example ';
				$hosts[] = 'https://www.3ds.bank.example/challenge?x=1';
				$hosts[] = 'paypal.com';
				$hosts[] = 'not a host';
				$hosts[] = array( 'nested' );
				return $hosts;
			};

			$hosts = Attribution_Provider::get_ignored_referrer_hosts();

			$this->assertContains( 'acs.issuer-bank.example', $hosts );
			$this->assertContains( '3ds.bank.example', $hosts );
			$this->assertSame( 1, count( array_keys( $hosts, 'paypal.com', true ) ) );
			$this->assertNotContains( 'not a host', $hosts );
			$this->assertSame( array_values( $hosts ), $hosts );
		}

		public function test_ignored_referrer_hosts_filter_reaches_the_attribution_script_config(): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_ignored_referrer_hosts'] = static function (): array {
				return array( 'acs.issuer-bank.example' );
			};

			$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( Plugin::class, 'build_attribution_config' );
			$method->setAccessible( true );
			$config = $method->invoke(
				$plugin,
				array(),
				array(
					'cookie_name'     => 'ct_consent',
					'require_consent' => false,
				),
				90,
				false,
				'',
				'',
				false
			);

			$this->assertSame( array( 'acs.issuer-bank.example' ), $config['ignoredReferrerHosts'] );
		}

		/**
		 * Create a WooCommerce order double that records each save.
		 *
		 * @param int $id Order ID.
		 * @return object
		 */
		private function make_order( int $id ): object {
			return new class( $id ) {
				/**
				 * In-memory order metadata.
				 *
				 * @var array<string,mixed>
				 */
				public array $meta = array();

				/**
				 * Metadata snapshot at each save().
				 *
				 * @var array<int,array<string,mixed>>
				 */
				public array $saved = array();

				/**
				 * Order ID.
				 *
				 * @var int
				 */
				private int $id;

				public function __construct( int $id ) {
					$this->id = $id;
				}

				public function get_id(): int {
					return $this->id;
				}

				public function update_meta_data( $key, $value ): void {
					$this->meta[ (string) $key ] = $value;
				}

				public function save(): int {
					$this->saved[] = $this->meta;
					return $this->id;
				}
			};
		}
	}
}
