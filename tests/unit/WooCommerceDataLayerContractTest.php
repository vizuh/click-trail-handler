<?php
/**
 * WooCommerce dataLayer contract tests.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-woocommerce.php';

use CLICUTCL\Integrations\WooCommerce;
use PHPUnit\Framework\TestCase;

/**
 * Class WooCommerceDataLayerContractTest
 */
final class WooCommerceDataLayerContractTest extends TestCase {

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
