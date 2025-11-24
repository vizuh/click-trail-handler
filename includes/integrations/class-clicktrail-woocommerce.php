<?php

class ClickTrail_WooCommerce_Integration {

    public function init() {
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_attribution' ), 10, 2 );
    }

    /**
     * Save attribution data to the order.
     *
     * @param WC_Order $order
     * @param array    $data
     */
    public function save_order_attribution( $order, $data ) {
        $attribution = clicktrail_get_attribution();
        if ( ! $attribution ) {
            return;
        }

        // First Touch
        if ( isset( $attribution['first_touch'] ) ) {
            foreach ( $attribution['first_touch'] as $key => $value ) {
                $meta_key = sanitize_key( $key );
                if ( '' !== $meta_key ) {
                    $order->update_meta_data( '_ct_ft_' . $meta_key, sanitize_text_field( $value ) );
                }
            }
        }

        // Last Touch
        if ( isset( $attribution['last_touch'] ) ) {
            foreach ( $attribution['last_touch'] as $key => $value ) {
                $meta_key = sanitize_key( $key );
                if ( '' !== $meta_key ) {
                    $order->update_meta_data( '_ct_lt_' . $meta_key, sanitize_text_field( $value ) );
                }
            }
        }

        // Session Count
        if ( isset( $attribution['session_count'] ) ) {
            $order->update_meta_data( '_ct_session_count', absint( $attribution['session_count'] ) );
        }
    }

}
