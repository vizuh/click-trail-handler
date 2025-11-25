<?php

class ClickTrail_WooCommerce_Integration {

	public function init() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_attribution' ), 10, 2 );
	}

	/**
	 * Save attribution data to the order.
	 *
	 * @param WC_Order $order
	 * @param array $data
	 */
public function save_order_attribution( $order, $data ) {
$attribution = clicktrail_get_attribution();
		if ( ! $attribution ) {
			return;
		}

                foreach ( $attribution as $key => $value ) {
                        if ( 'session_count' === $key ) {
                                $order->update_meta_data( '_ct_session_count', absint( $value ) );
                                continue;
                        }

                        $order->update_meta_data( '_ct_' . $key, $value );
                }
        }

}
