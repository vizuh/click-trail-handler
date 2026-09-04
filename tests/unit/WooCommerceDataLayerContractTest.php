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

use CLICUTCL\Core\Storage\Option_Cache;
use CLICUTCL\Integrations\WooCommerce;
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
		$method->setAccessible( true );

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
}
