<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLICUTCL_WooCommerce_Integration {

	public function init() {
		// Safety check: ensure WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_attribution' ), 10, 2 );
		add_action( 'woocommerce_thankyou', array( $this, 'push_purchase_event' ), 20, 1 );
	}

	/**
	 * Save attribution data to the order.
	 *
	 * @param WC_Order $order
	 * @param array    $data
	 */
	public function save_order_attribution( $order, $data ) {
		$attribution = clicutcl_get_attribution();
		if ( ! $attribution ) {
			return;
		}

		foreach ( $attribution as $key => $value ) {
			$meta_key = sanitize_key( $key );
			if ( '' === $meta_key ) {
				continue;
			}

			if ( 'session_count' === $meta_key ) {
				$order->update_meta_data( '_clicutcl_session_count', absint( $value ) );
				continue;
			}

			$order->update_meta_data( '_clicutcl_' . $meta_key, sanitize_text_field( $value ) );
		}
	}

	/**
	 * Output a GA4-ready purchase event on the thank-you page enriched with attribution.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function push_purchase_event( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		// Duplicate Prevention: Check if we already tracked this order
		if ( get_post_meta( $order_id, '_clicutcl_tracking_sent', true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$attribution = $this->collect_order_attribution( $order );
		$flat_attr   = $this->flatten_attribution_for_event( $attribution );

		$items_js = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product    = $item->get_product();
			$items_js[] = array(
				'item_id'   => $product ? $product->get_id() : $item_id,
				'item_name' => $item->get_name(),
				'price'     => (float) $order->get_item_total( $item, false ),
				'quantity'  => (int) $item->get_quantity(),
			);
		}

		$payload = array_merge(
			array(
				'event'     => 'purchase',
				'ecommerce' => array(
					'transaction_id' => (string) $order->get_order_number(),
					'value'          => (float) $order->get_total(),
					'currency'       => $order->get_currency(),
					'items'          => $items_js,
				),
			),
			$flat_attr
		);
		?>
		<script>
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push(<?php echo wp_json_encode( $payload ); ?>);
		</script>
		<?php

		// Mark order as tracked to prevent duplicates on refresh
		update_post_meta( $order_id, '_clicutcl_tracking_sent', 'yes' );
	}

	/**
	 * Gather attribution data saved on the order or fall back to the current cookie.
	 *
	 * @param WC_Order $order Order instance.
	 *
	 * @return array
	 */
	private function collect_order_attribution( $order ) {
		$attribution = array(
			'first_touch' => array(),
			'last_touch'  => array(),
		);

		foreach ( $order->get_meta_data() as $meta ) {
			$key   = $meta->key;
			$value = $meta->value;

			if ( 0 === strpos( $key, '_clicutcl_ft_' ) ) {
				$attribution['first_touch'][ substr( $key, 13 ) ] = $value;
			}

			if ( 0 === strpos( $key, '_clicutcl_lt_' ) ) {
				$attribution['last_touch'][ substr( $key, 13 ) ] = $value;
			}
		}

		if ( empty( $attribution['first_touch'] ) && empty( $attribution['last_touch'] ) ) {
			$cookie_attr = clicutcl_get_attribution();
			if ( $cookie_attr ) {
				$attribution = wp_parse_args( $cookie_attr, $attribution );
			}
		}

		return $attribution;
	}

	/**
	 * Flatten attribution data to ft_* / lt_* fields for GA4.
	 *
	 * @param array $attribution Nested attribution.
	 *
	 * @return array
	 */
	private function flatten_attribution_for_event( $attribution ) {
		$flat = array(
			'ft_source'      => '',
			'ft_medium'      => '',
			'ft_campaign'    => '',
			'ft_term'        => '',
			'ft_content'     => '',
			'ft_gclid'       => '',
			'ft_fbclid'      => '',
			'ft_wbraid'      => '',
			'ft_gbraid'      => '',
			'ft_msclkid'     => '',
			'ft_ttclid'      => '',
			'ft_twclid'      => '',
			'ft_li_fat_id'   => '',
			'ft_ScCid'       => '',
			'ft_sc_click_id' => '',
			'ft_epik'        => '',
			'lt_source'      => '',
			'lt_medium'      => '',
			'lt_campaign'    => '',
			'lt_term'        => '',
			'lt_content'     => '',
			'lt_gclid'       => '',
			'lt_fbclid'      => '',
			'lt_wbraid'      => '',
			'lt_gbraid'      => '',
			'lt_msclkid'     => '',
			'lt_ttclid'      => '',
			'lt_twclid'      => '',
			'lt_li_fat_id'   => '',
			'lt_ScCid'       => '',
			'lt_sc_click_id' => '',
			'lt_epik'        => '',
		);

		$map_key = function( $key ) {
			$map = array(
				'utm_source'   => 'source',
				'utm_medium'   => 'medium',
				'utm_campaign' => 'campaign',
				'utm_term'     => 'term',
				'utm_content'  => 'content',
			);

			return isset( $map[ $key ] ) ? $map[ $key ] : $key;
		};

		$assign_touch = function( $touch_key, $prefix ) use ( &$flat, $attribution, $map_key ) {
			if ( isset( $attribution[ $touch_key ] ) && is_array( $attribution[ $touch_key ] ) ) {
				foreach ( $attribution[ $touch_key ] as $key => $value ) {
					$mapped                    = $map_key( $key );
					$flat[ $prefix . $mapped ] = $value;
				}
			}
		};

		$assign_touch( 'first_touch', 'ft_' );
		$assign_touch( 'last_touch', 'lt_' );

		return $flat;
	}

}
