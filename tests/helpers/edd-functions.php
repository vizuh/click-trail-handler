<?php
/**
 * Easy Digital Downloads order API doubles backed by `$GLOBALS['clicktrail_test_edd_meta']`.
 *
 * @package ClickTrail
 */

if ( ! function_exists( 'edd_update_order_meta' ) ) {
	/**
	 * Mirror `update_metadata()`: values are unslashed before they are stored.
	 */
	function edd_update_order_meta( $order_id, $meta_key, $meta_value ) {
		$GLOBALS['clicktrail_test_edd_meta'][ (int) $order_id ][ (string) $meta_key ] = is_string( $meta_value ) ? stripslashes( $meta_value ) : $meta_value;
		return true;
	}
}

if ( ! function_exists( 'edd_get_order_meta' ) ) {
	/**
	 * Mirror `get_metadata()`: no key returns every key as a list of values.
	 */
	function edd_get_order_meta( $order_id, $key = '', $single = false ) {
		$all = $GLOBALS['clicktrail_test_edd_meta'][ (int) $order_id ] ?? array();
		if ( '' === $key ) {
			return array_map(
				static function ( $value ) {
					return array( $value );
				},
				$all
			);
		}
		if ( ! array_key_exists( $key, $all ) ) {
			return $single ? '' : array();
		}
		return $single ? $all[ $key ] : array( $all[ $key ] );
	}
}

if ( ! function_exists( 'edd_delete_order_meta' ) ) {
	/**
	 * Mirror `delete_metadata()` for one order and key.
	 */
	function edd_delete_order_meta( $order_id, $meta_key, $meta_value = '' ) {
		if ( ! empty( $GLOBALS['clicktrail_test_edd_delete_fails'] ) ) {
			return false;
		}
		if ( ! isset( $GLOBALS['clicktrail_test_edd_meta'][ (int) $order_id ][ (string) $meta_key ] ) ) {
			return false;
		}
		unset( $GLOBALS['clicktrail_test_edd_meta'][ (int) $order_id ][ (string) $meta_key ] );
		return true;
	}
}

if ( ! function_exists( 'edd_get_orders' ) ) {
	/**
	 * Order query double over `$GLOBALS['clicktrail_test_edd_orders']` (objects with id, email, date_created).
	 *
	 * Honors `email`, `meta_query` (any ClickTrail-managed meta), `date_created_query` (`before`),
	 * `number` and `offset`, and records every call in `clicktrail_test_edd_order_queries`.
	 */
	function edd_get_orders( $args = array() ) {
		$GLOBALS['clicktrail_test_edd_order_queries'][] = $args;

		$orders = $GLOBALS['clicktrail_test_edd_orders'] ?? array();
		if ( isset( $args['email'] ) ) {
			$orders = array_filter(
				$orders,
				static function ( $order ) use ( $args ): bool {
					return $order->email === $args['email'];
				}
			);
		}
		if ( isset( $args['date_created_query'][0]['before'] ) ) {
			$before = (string) $args['date_created_query'][0]['before'];
			$orders = array_filter(
				$orders,
				static function ( $order ) use ( $before ): bool {
					return $order->date_created < $before;
				}
			);
		}
		if ( isset( $args['meta_query'] ) ) {
			$orders = array_filter(
				$orders,
				static function ( $order ): bool {
					$meta = array();
					foreach ( $GLOBALS['clicktrail_test_edd_meta'][ $order->id ] ?? array() as $key => $value ) {
						$meta[] = array(
							'key'   => $key,
							'value' => $value,
						);
					}
					return ! empty( CLICUTCL\Privacy\Woo_Order_Privacy::managed_meta( $meta ) );
				}
			);
		}

		return array_slice( array_values( $orders ), (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 30 ) );
	}
}
