<?php
/**
 * WooCommerce dataLayer contract tests.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-woocommerce.php';
require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
require_once dirname( __DIR__, 2 ) . '/includes/settings/class-attribution-settings.php';
require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-decision-v1.php';
require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-resolver-v1.php';
require_once dirname( __DIR__, 2 ) . '/includes/Consent/class-snapshot-v1.php';
require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-setting.php';
require_once dirname( __DIR__, 2 ) . '/includes/Modules/GTM/class-gtm-settings.php';

use CLICUTCL\Core\Storage\Option_Cache;
use CLICUTCL\Integrations\WooCommerce;
use CLICUTCL\Modules\GTM\GTM_Settings;
use CLICUTCL\Settings\Attribution_Settings;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		++$GLOBALS['clicktrail_test_woo_order_reads'];
		return null;
	}
}

/**
 * Class WooCommerceDataLayerContractTest
 */
final class WooCommerceDataLayerContractTest extends TestCase {

	/** @dataProvider thankYouConsentProvider */
	public function test_thank_you_consent_is_checked_before_order_reads_or_output( bool $required, ?bool $marketing, int $expected_reads ): void {
		$settings = Attribution_Settings::get_all();
		$cookies  = $_COOKIE;
		Option_Cache::set( Attribution_Settings::OPTION_NAME, array( 'require_consent' => $required ) );
		$_COOKIE = null === $marketing ? array() : array( 'ct_consent_state' => wp_json_encode( array( 'marketing' => $marketing ) ) );
		$GLOBALS['clicktrail_test_woo_order_reads'] = 0;
		ob_start();
		try {
			( new WooCommerce() )->push_purchase_event( 123 );
			$this->assertSame( '', ob_get_contents() );
			$this->assertSame( $expected_reads, $GLOBALS['clicktrail_test_woo_order_reads'] );
		} finally {
			ob_end_clean();
			Option_Cache::set( Attribution_Settings::OPTION_NAME, $settings );
			$_COOKIE = $cookies;
			unset( $GLOBALS['clicktrail_test_woo_order_reads'] );
		}
	}

	public static function thankYouConsentProvider(): array {
		return array(
			'withdrawn'    => array( true, false, 0 ),
			'unresolved'   => array( true, null, 0 ),
			'granted'      => array( true, true, 1 ),
			'not-required' => array( false, null, 1 ),
		);
	}

	public function test_nested_attribution_is_flattened_for_purchase_data_layer(): void {
		$method = new ReflectionMethod( WooCommerce::class, 'flatten_attribution_for_event' );

		$result = $method->invoke(
			new WooCommerce(),
			array(
				'first_touch' => array(
					'source'   => 'google',
					'medium'   => 'cpc',
					'campaign' => 'spring',
				),
				'last_touch'  => array(
					'source' => 'newsletter',
				),
			)
		);

		$this->assertSame( 'google', $result['ft_source'] );
		$this->assertSame( 'cpc', $result['ft_medium'] );
		$this->assertSame( 'spring', $result['ft_campaign'] );
		$this->assertSame( 'newsletter', $result['lt_source'] );
	}

	public function test_legacy_top_level_click_id_yields_to_last_touch(): void {
		$method = new ReflectionMethod( WooCommerce::class, 'flatten_attribution_for_event' );

		$result = $method->invoke(
			new WooCommerce(),
			array(
				'gclid'       => 'TEST_GCLID_123',
				'first_touch' => array( 'gclid' => 'TEST_GCLID_123' ),
				'last_touch'  => array( 'gclid' => 'TEST_PERSIST_456' ),
			)
		);

		$this->assertSame( 'TEST_PERSIST_456', $result['gclid'] );
		$this->assertSame( 'TEST_GCLID_123', $result['ft_gclid'] );
		$this->assertSame( 'TEST_PERSIST_456', $result['lt_gclid'] );
	}

	/** @dataProvider enhancedDataLayerProvider */
	public function test_purchase_data_layer_event_id_matches_server_event_id( int $enhanced ): void {
		$gtm = ( new GTM_Settings() )->get();
		Option_Cache::set( GTM_Settings::OPTION, array_merge( GTM_Settings::defaults(), array( 'woo_enhanced_datalayer' => $enhanced ) ) );
		try {
			$woo     = new WooCommerce();
			$order   = $this->stub_order( 4321 );
			$payload = ( new ReflectionMethod( WooCommerce::class, 'build_purchase_payload' ) )->invoke( $woo, $order, array(), array() );
			$push    = ( new ReflectionMethod( WooCommerce::class, 'build_purchase_datalayer_payload' ) )->invoke( $woo, $payload, array() );

			$this->assertSame( 'purchase_4321', $payload['event_id'] );
			$this->assertSame( $payload['event_id'], $push['event_id'] );
			$this->assertSame( 'purchase', $push['event'] );
			$this->assertSame( 'WC-4321', $push['ecommerce']['transaction_id'] );
		} finally {
			Option_Cache::set( GTM_Settings::OPTION, $gtm );
		}
	}

	public static function enhancedDataLayerProvider(): array {
		return array(
			'enhanced-off' => array( 0 ),
			'enhanced-on'  => array( 1 ),
		);
	}

	/**
	 * Minimal WC_Order stand-in covering the accessors the purchase builders call.
	 *
	 * @param int $id Order ID.
	 * @return object
	 */
	private function stub_order( int $id ): object {
		return new class( $id ) {
			public function __construct( private int $id ) {}
			public function get_id(): int { return $this->id; }
			public function get_order_number(): string { return 'WC-' . $this->id; }
			public function get_total(): float { return 99.5; }
			public function get_currency(): string { return 'EUR'; }
			public function get_coupon_codes(): array { return array(); }
			public function get_status(): string { return 'processing'; }
			public function get_items(): array { return array(); }
			public function get_meta( string $key, bool $single = true ): string { return ''; }
		};
	}
}
