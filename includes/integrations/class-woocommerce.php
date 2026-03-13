<?php
/**
 * WooCommerce Integration
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations;

use CLICUTCL\Core\Attribution_Provider;
use CLICUTCL\Utils\Attribution;
use CLICUTCL\Server_Side\Dispatcher;
use CLICUTCL\Server_Side\Consent;
use CLICUTCL\Tracking\Identity_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooCommerce
 */
class WooCommerce {

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Safety check: ensure WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_attribution' ), 10, 2 );
		add_action( 'woocommerce_thankyou', array( $this, 'push_purchase_event' ), 20, 1 );
		
		// Output hidden fields for JS Injection (Cache Resilience)
		add_action( 'woocommerce_after_order_notes', array( $this, 'output_hidden_checkout_fields' ) );
	}

	/**
	 * Output hidden fields in the checkout form so JS can populate them.
	 * This ensures attribution is captured even if server-side cookie reading fails due to caching.
	 *
	 * @param \WC_Checkout $checkout Checkout object.
	 */
	public function output_hidden_checkout_fields( $checkout ) {
		$fields = array_map(
			static function( $key ) {
				return 'ct_' . $key;
			},
			Attribution_Provider::get_field_mapping()
		);

		echo '<div class="clicutcl-checkout-fields" style="display:none;">';
		foreach ( $fields as $field ) {
			// Output empty inputs; JS Injector will fill them
			echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="" />';
		}
		echo '</div>';
	}

	/**
	 * Save attribution data to the order.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Request Data.
	 */
	public function save_order_attribution( $order, $data ) {
		// 1. Try server-side cookie first (most reliable if not stripped)
		$attribution = Attribution::get();
		
		// 2. Fallback to POST data (Client-Side Injection)
		if ( empty( $attribution ) ) {
			$attribution = $this->collect_from_post_data( $data );
		}
		
		if ( ! $attribution ) {
			return;
		}

		foreach ( $attribution as $key => $value ) {
			$meta_key = sanitize_key( $key );
			if ( '' === $meta_key ) {
				continue;
			}
			
			// Map standard keys to storage keys if needed, but our array usually is flat or structured?
			// Attribution::get() returns flat? No, it implies structured in other parts.
			// Let's assume flat for the fallback logic or handle structure.
			// Actually `Attribution::get()` returns the cookie array (structured).
			// We need to flatten our POST data to match that structure or just save what we have.
			
			if ( 'session_count' === $meta_key ) {
				$order->update_meta_data( '_clicutcl_session_count', absint( $value ) );
				continue;
			}

			$order->update_meta_data( '_clicutcl_' . $meta_key, sanitize_text_field( $value ) );
		}
	}

	/**
	 * Collect attribution from POST data (Fallback).
	 *
	 * @param array $data Request data.
	 * @return array|null
	 */
	private function collect_from_post_data( $data ) {
		// Nonce check for WooCommerce checkout security
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'woocommerce-process_checkout' ) ) {
			return null; 
		}

		$attr = array();

		foreach ( Attribution_Provider::get_field_mapping() as $key ) {
			$input_name = 'ct_' . $key;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in parent function collect_from_post_data.
			if ( empty( $_POST[ $input_name ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in parent function collect_from_post_data.
			$attr[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $input_name ] ) );
		}

		if ( empty( $attr ) ) {
			return null;
		}

		return Attribution_Provider::sanitize( $attr );
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

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_clicutcl_tracking_sent', true ) ) {
			return;
		}

		$attribution = $this->collect_order_attribution( $order );
		$flat_attr   = $this->flatten_attribution_for_event( $attribution );
		$identity    = $this->resolve_purchase_identity( $order );
		$payload     = $this->build_purchase_payload( $order, $attribution, $identity );
		$defaults    = $payload;

		/**
		 * Filter the final WooCommerce purchase payload before ClickTrail pushes
		 * it into `dataLayer` and the server-side dispatcher.
		 *
		 * @param array     $payload Purchase payload.
		 * @param \WC_Order $order   Order instance.
		 */
		$payload   = apply_filters( 'clicutcl_woocommerce_purchase_payload', $payload, $order );
		$payload   = is_array( $payload ) ? wp_parse_args( $payload, $defaults ) : $defaults;
		$datalayer   = $this->build_purchase_datalayer_payload( $payload, $flat_attr );

		Dispatcher::dispatch_purchase( $payload );
		?>
		<script>
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push(<?php echo wp_json_encode( $datalayer ); ?>);
		</script>
		<?php

		$order->update_meta_data( '_clicutcl_tracking_sent', 'yes' );
		$order->save();
	}

	/**
	 * Build the full WooCommerce purchase payload used for both `dataLayer`
	 * and server-side dispatch.
	 *
	 * @param \WC_Order $order       Order instance.
	 * @param array     $attribution Order attribution payload.
	 * @param array     $identity    Consent-aware identity payload.
	 * @return array<string, mixed>
	 */
	private function build_purchase_payload( $order, array $attribution, array $identity ): array {
		$commerce = $this->build_purchase_commerce( $order );

		return array(
			'event_id'       => 'purchase_' . (int) $order->get_id(),
			'order_id'       => (int) $order->get_id(),
			'transaction_id' => (string) $order->get_order_number(),
			'value'          => (float) $order->get_total(),
			'currency'       => sanitize_text_field( (string) $order->get_currency() ),
			'items'          => $commerce['items'],
			'attribution'    => $attribution,
			'identity'       => $identity,
			'commerce'       => $commerce,
			'meta'           => $this->build_purchase_meta( $order ),
		);
	}

	/**
	 * Build the nested commerce payload for WooCommerce purchases.
	 *
	 * @param \WC_Order $order Order instance.
	 * @return array<string, mixed>
	 */
	private function build_purchase_commerce( $order ): array {
		$items         = $this->build_purchase_items( $order );
		$item_quantity = 0;

		foreach ( $items as $item ) {
			$item_quantity += isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
		}

		return array(
			'transaction_id' => (string) $order->get_order_number(),
			'value'          => (float) $order->get_total(),
			'currency'       => sanitize_text_field( (string) $order->get_currency() ),
			'subtotal'       => method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : 0.0,
			'tax_total'      => method_exists( $order, 'get_total_tax' ) ? (float) $order->get_total_tax() : 0.0,
			'shipping_total' => method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0,
			'discount_total' => method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0,
			'discount_codes' => array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						(array) $order->get_coupon_codes()
					)
				)
			),
			'status'         => sanitize_key( (string) $order->get_status() ),
			'order_currency' => sanitize_text_field( (string) $order->get_currency() ),
			'item_quantity'  => $item_quantity,
			'items'          => $items,
		);
	}

	/**
	 * Build purchase line items with additive WooCommerce metadata.
	 *
	 * @param \WC_Order $order Order instance.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_purchase_items( $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = method_exists( $item, 'get_product_id' ) ? absint( $item->get_product_id() ) : 0;
			$variation_id = method_exists( $item, 'get_variation_id' ) ? absint( $item->get_variation_id() ) : 0;
			$product      = $item->get_product();
			$resolved_id  = $product ? absint( $product->get_id() ) : ( $variation_id ? $variation_id : $product_id );
			$items[]      = array(
				'item_id'    => $resolved_id ? $resolved_id : absint( $item_id ),
				'item_name'  => sanitize_text_field( (string) $item->get_name() ),
				'price'      => (float) $order->get_item_total( $item, false ),
				'quantity'   => (int) $item->get_quantity(),
				'product_id' => $resolved_id,
				'sku'        => $product ? sanitize_text_field( (string) $product->get_sku() ) : '',
				'variant'    => $product ? $this->get_product_variant( $product ) : '',
				'categories' => $product ? $this->get_product_categories( $product ) : array(),
			);
		}

		return $items;
	}

	/**
	 * Build WooCommerce order/customer metadata for purchase payloads.
	 *
	 * @param \WC_Order $order Order instance.
	 * @return array<string, mixed>
	 */
	private function build_purchase_meta( $order ): array {
		$customer_id = method_exists( $order, 'get_customer_id' ) ? absint( $order->get_customer_id() ) : 0;
		$order_date  = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
		$created_at  = $order_date ? $order_date->date( DATE_ATOM ) : '';
		$customer_created_at = '';

		if ( $customer_id ) {
			$user = get_userdata( $customer_id );
			if ( $user && ! empty( $user->user_registered ) ) {
				$customer_created_at = mysql2date( DATE_ATOM, $user->user_registered, false );
			}
		}

		return array(
			'customer_id'         => $customer_id,
			'customer_created_at' => sanitize_text_field( (string) $customer_created_at ),
			'order_created_at'    => sanitize_text_field( (string) $created_at ),
		);
	}

	/**
	 * Convert the filtered purchase payload into the `dataLayer` event shape.
	 *
	 * @param array $payload   Final purchase payload.
	 * @param array $flat_attr Flat attribution fields for `dataLayer`.
	 * @return array<string, mixed>
	 */
	private function build_purchase_datalayer_payload( array $payload, array $flat_attr ): array {
		$commerce = isset( $payload['commerce'] ) && is_array( $payload['commerce'] ) ? $payload['commerce'] : array();
		$items    = isset( $commerce['items'] ) && is_array( $commerce['items'] ) ? $commerce['items'] : ( isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array() );

		$ecommerce = array(
			'transaction_id' => isset( $commerce['transaction_id'] ) ? (string) $commerce['transaction_id'] : (string) ( $payload['transaction_id'] ?? '' ),
			'value'          => isset( $commerce['value'] ) ? (float) $commerce['value'] : (float) ( $payload['value'] ?? 0 ),
			'currency'       => isset( $commerce['currency'] ) ? (string) $commerce['currency'] : (string) ( $payload['currency'] ?? '' ),
			'items'          => $items,
		);

		foreach ( array( 'subtotal', 'tax_total', 'shipping_total', 'discount_total', 'item_quantity' ) as $field ) {
			if ( isset( $commerce[ $field ] ) ) {
				$ecommerce[ $field ] = (float) $commerce[ $field ];
			}
		}

		if ( ! empty( $commerce['discount_codes'] ) && is_array( $commerce['discount_codes'] ) ) {
			$ecommerce['discount_codes'] = array_values( array_map( 'sanitize_text_field', $commerce['discount_codes'] ) );
		}
		if ( ! empty( $commerce['status'] ) ) {
			$ecommerce['status'] = sanitize_key( (string) $commerce['status'] );
		}
		if ( ! empty( $commerce['order_currency'] ) ) {
			$ecommerce['order_currency'] = sanitize_text_field( (string) $commerce['order_currency'] );
		}

		return array_merge(
			array(
				'event'     => 'purchase',
				'ecommerce' => $ecommerce,
			),
			$flat_attr
		);
	}

	/**
	 * Resolve a variation summary string for WooCommerce items.
	 *
	 * @param \WC_Product $product Product instance.
	 * @return string
	 */
	private function get_product_variant( $product ): string {
		if ( ! function_exists( 'wc_get_formatted_variation' ) || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variation' ) ) {
			return '';
		}

		return sanitize_text_field( wp_strip_all_tags( (string) wc_get_formatted_variation( $product, true, false, false ) ) );
	}

	/**
	 * Resolve WooCommerce product category names.
	 *
	 * @param \WC_Product $product Product instance.
	 * @return array<int, string>
	 */
	private function get_product_categories( $product ): array {
		if ( ! function_exists( 'wc_get_product_terms' ) || ! method_exists( $product, 'get_id' ) ) {
			return array();
		}

		$term_product_id = absint( $product->get_id() );
		if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$term_product_id = absint( $product->get_parent_id() );
		}

		if ( ! $term_product_id ) {
			return array();
		}

		$terms = wc_get_product_terms(
			$term_product_id,
			'product_cat',
			array(
				'fields' => 'names',
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function( $term_name ) {
						return sanitize_text_field( (string) $term_name );
					},
					$terms
				)
			)
		);
	}

	/**
	 * Gather attribution data saved on the order or fall back to the current cookie.
	 *
	 * @param \WC_Order $order Order instance.
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
				continue;
			}

			if ( 0 === strpos( $key, '_clicutcl_lt_' ) ) {
				$attribution['last_touch'][ substr( $key, 13 ) ] = $value;
				continue;
			}

			if ( 0 === strpos( $key, '_clicutcl_' ) ) {
				$meta_key = substr( $key, 10 );
				if ( '' !== $meta_key && ! in_array( $meta_key, array( 'tracking_sent' ), true ) ) {
					$attribution[ $meta_key ] = $value;
				}
			}
		}

		if ( empty( $attribution['first_touch'] ) && empty( $attribution['last_touch'] ) ) {
			$cookie_attr = Attribution::get();
			if ( $cookie_attr ) {
				$attribution = wp_parse_args( $cookie_attr, $attribution );
			}
		}

		return $this->normalize_attribution_structure( $attribution );
	}

	/**
	 * Resolve purchase identity for server-side delivery.
	 *
	 * @param \WC_Order $order Order instance.
	 * @return array
	 */
	private function resolve_purchase_identity( $order ) {
		$input = array(
			'email' => sanitize_email( (string) $order->get_billing_email() ),
			'phone' => sanitize_text_field( (string) $order->get_billing_phone() ),
		);

		$ip = '';
		if ( method_exists( $order, 'get_customer_ip_address' ) ) {
			$ip = (string) $order->get_customer_ip_address();
		}
		if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by Identity_Resolver.
			$ip = wp_unslash( $_SERVER['REMOTE_ADDR'] );
		}
		if ( '' !== $ip ) {
			$input['ip'] = $ip;
		}

		$user_agent = '';
		if ( method_exists( $order, 'get_customer_user_agent' ) ) {
			$user_agent = (string) $order->get_customer_user_agent();
		}
		if ( '' === $user_agent ) {
			$user_agent = (string) $order->get_meta( '_customer_user_agent', true );
		}
		if ( '' === $user_agent && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Identity_Resolver.
			$user_agent = wp_unslash( $_SERVER['HTTP_USER_AGENT'] );
		}
		if ( '' !== $user_agent ) {
			$input['user_agent'] = $user_agent;
		}

		$resolver = new Identity_Resolver();

		return $resolver->resolve(
			$input,
			array(
				'marketing_allowed' => Consent::marketing_allowed(),
				'include_ip_ua'     => true,
			)
		);
	}

	/**
	 * Flatten attribution data to ft_* / lt_* fields for GA4.
	 *
	 * @param array $attribution Nested attribution.
	 *
	 * @return array
	 */
	private function flatten_attribution_for_event( $attribution ) {
		$raw_attribution = Attribution_Provider::sanitize( is_array( $attribution ) ? $attribution : array() );
		$attribution     = $this->normalize_attribution_structure( $raw_attribution );

		$flat = array_fill_keys(
			array_filter(
				Attribution_Provider::get_field_mapping(),
				static function( $key ) {
					return 0 === strpos( $key, 'ft_' ) || 0 === strpos( $key, 'lt_' );
				}
			),
			''
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

		foreach ( array_merge( Attribution_Provider::get_click_id_fields(), Attribution_Provider::get_browser_identifier_fields() ) as $key ) {
			if ( isset( $raw_attribution[ $key ] ) && '' !== (string) $raw_attribution[ $key ] ) {
				$flat[ $key ] = $raw_attribution[ $key ];
			}
		}

		return $flat;
	}

	/**
	 * Normalize mixed attribution payloads into first_touch/last_touch shape.
	 *
	 * Accepts nested shape (first_touch / last_touch) and flat ft_/lt_ prefixed keys.
	 *
	 * @param array $attribution Raw attribution payload.
	 * @return array
	 */
	private function normalize_attribution_structure( $attribution ) {
		$normalized = array(
			'first_touch' => array(),
			'last_touch'  => array(),
		);

		if ( ! is_array( $attribution ) ) {
			return $normalized;
		}

		if ( isset( $attribution['first_touch'] ) && is_array( $attribution['first_touch'] ) ) {
			$normalized['first_touch'] = $attribution['first_touch'];
		}
		if ( isset( $attribution['last_touch'] ) && is_array( $attribution['last_touch'] ) ) {
			$normalized['last_touch'] = $attribution['last_touch'];
		}

		foreach ( $attribution as $key => $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}

			$key = (string) $key;
			if ( 0 === strpos( $key, 'ft_' ) ) {
				$touch_key = substr( $key, 3 );
				if ( 'sc_click_id' === $touch_key || 'sccid' === $touch_key || 'ScCid' === $touch_key ) {
					$touch_key = 'sccid';
				}
				$normalized['first_touch'][ $touch_key ] = (string) $value;
				continue;
			}
			if ( 0 === strpos( $key, 'lt_' ) ) {
				$touch_key = substr( $key, 3 );
				if ( 'sc_click_id' === $touch_key || 'sccid' === $touch_key || 'ScCid' === $touch_key ) {
					$touch_key = 'sccid';
				}
				$normalized['last_touch'][ $touch_key ] = (string) $value;
				continue;
			}

			if ( ! in_array( $key, array( 'first_touch', 'last_touch' ), true ) ) {
				$normalized[ $key ] = (string) $value;
			}
		}

		return $normalized;
	}

}
