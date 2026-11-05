<?php
/**
 * Easy Digital Downloads Integration
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations;

use CLICUTCL\Consent\Snapshot_V1;
use CLICUTCL\Core\Attribution_Provider;
use CLICUTCL\Privacy\Edd_Order_Privacy;
use CLICUTCL\Privacy\Woo_Order_Privacy;
use CLICUTCL\Server_Side\Consent;
use CLICUTCL\Server_Side\Dispatcher;
use CLICUTCL\Tracking\Claim_Lock;
use CLICUTCL\Tracking\Identity_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Edd
 *
 * Needs the EDD 3.0+ order tables and `edd_*_order_meta()` API; EDD 2.x payments are not supported.
 * Attribution is written when the order is built (the checkout request still carries the buyer's
 * cookies, including for off-site gateways), and one `purchase` is emitted from the confirmation
 * receipt.
 */
class Edd {
	/**
	 * Order meta key for the consent snapshot captured at checkout.
	 */
	public const CONSENT_META_KEY = '_clicutcl_consent';

	/**
	 * Order meta key marking the receipt purchase as emitted (same key as WooCommerce orders).
	 * Retention and subject erase keep it; see `Edd_Order_Privacy`.
	 */
	public const SENT_META_KEY = Edd_Order_Privacy::SENT_MARKER_KEY;

	/**
	 * Prefix for the per-order receipt dispatch lock (object cache key or option name).
	 */
	public const PURCHASE_LOCK_PREFIX = 'clicutcl_edd_purchase_lock_';

	/**
	 * Seconds a receipt dispatch lock may be held before it is considered stale.
	 */
	private const PURCHASE_LOCK_TTL = 30;

	/**
	 * Order statuses whose receipt is a completed sale.
	 *
	 * Deliberately narrower than `edd_get_complete_order_statuses()`, which also lists
	 * refunded and revoked orders: a purchase must not fire for those.
	 */
	private const PAID_STATUSES = array( 'complete', 'completed', 'publish' );

	/**
	 * Initialize hooks.
	 */
	public function init() {
		if ( ! class_exists( 'Easy_Digital_Downloads' ) || ! function_exists( 'edd_update_order_meta' ) ) {
			return;
		}

		add_action( 'edd_built_order', array( $this, 'save_order_attribution' ), 10, 2 );
		add_action( 'edd_order_receipt_before_table', array( $this, 'push_purchase_event' ), 10, 2 );
	}

	/**
	 * Save the buyer's consent snapshot and attribution on a freshly built order.
	 *
	 * @param int   $order_id   Order ID.
	 * @param array $order_data Order data EDD built the order from (unused).
	 */
	public function save_order_attribution( $order_id, $order_data = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Parameter provided by the edd_built_order hook signature.
		$order_id = absint( $order_id );
		if ( ! $order_id || ! $this->is_customer_request_context() ) {
			return;
		}

		// Persist the buyer's consent snapshot regardless of attribution presence so
		// later requests without a visitor cookie can still honor checkout-time consent.
		$this->update_meta( $order_id, self::CONSENT_META_KEY, wp_json_encode( Consent::snapshot() ) );

		// A denied checkout must not accept stale cookie attribution values.
		if ( ! Attribution_Provider::should_populate() ) {
			return;
		}

		foreach ( Attribution_Provider::get_payload() as $key => $value ) {
			$meta_key = '_clicutcl_' . sanitize_key( (string) $key );

			// The provider sanitizes values but does not allowlist keys. Only keys the
			// export, erase and retention lifecycle manage are ever written.
			if ( ! is_scalar( $value ) || ! Woo_Order_Privacy::is_managed_meta_key( $meta_key ) ) {
				continue;
			}

			$this->update_meta( $order_id, $meta_key, sanitize_text_field( (string) $value ) );
		}
	}

	/**
	 * Output a GA4-ready purchase on the receipt and dispatch the server copy, once per order.
	 *
	 * @param object $order Receipt order (`EDD\Orders\Order`).
	 * @param array  $args  `[edd_receipt]` arguments (unused).
	 */
	public function push_purchase_event( $order, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Parameter provided by the edd_order_receipt_before_table hook signature.
		if ( ! is_object( $order ) || empty( $order->id ) || 'sale' !== (string) ( $order->type ?? 'sale' ) ) {
			return;
		}

		if ( ! in_array( (string) $order->status, self::PAID_STATUSES, true ) ) {
			return;
		}

		// An order older than the retention window has had its ClickTrail meta (including the
		// consent snapshot) expired, so a receipt opened from an old email or account page must
		// not count it again. An unreadable creation date is treated the same way: a missed
		// purchase is recoverable, a duplicate one is not.
		if ( ! $this->is_within_retention( (string) $order->date_created ) ) {
			return;
		}

		// Receipt output is browser-side: it uses the visitor's current consent, never
		// the order's historical grant, so a withdrawal wins.
		if ( ! Consent::marketing_allowed() ) {
			return;
		}

		$order_id = absint( $order->id );
		if ( $this->is_purchase_sent( $order_id ) ) {
			return;
		}

		// The marker is a read-then-write: two concurrent receipt loads both pass it.
		// Claim the order atomically before building or emitting anything.
		$lock = Claim_Lock::acquire( self::PURCHASE_LOCK_PREFIX . $order_id, self::PURCHASE_LOCK_TTL );
		if ( '' === $lock ) {
			return;
		}

		try {
			// A request that finished between our first read and our claim has already emitted.
			if ( $this->is_purchase_sent( $order_id ) ) {
				return;
			}

			$attribution = $this->collect_order_attribution( $order_id );
			$payload     = $this->build_purchase_payload( $order, $attribution );
			$defaults    = $payload;

			/**
			 * Filter the final EDD purchase payload before ClickTrail pushes it into
			 * `dataLayer` and the server-side dispatcher.
			 *
			 * @param array  $payload Purchase payload.
			 * @param object $order   EDD order.
			 */
			$payload   = apply_filters( 'clicutcl_edd_purchase_payload', $payload, $order );
			$payload   = is_array( $payload ) ? wp_parse_args( $payload, $defaults ) : $defaults;
			$datalayer = $this->build_purchase_datalayer_payload( $payload, $this->flatten_attribution( $attribution ) );

			$result = Dispatcher::dispatch_commerce_event( $payload );
			?>
			<script>
				window.dataLayer = window.dataLayer || [];
				window.dataLayer.push(<?php echo wp_json_encode( $datalayer ); ?>);
			</script>
			<?php

			if ( $this->should_mark_sent( $result ) ) {
				$this->update_meta( $order_id, self::SENT_META_KEY, 'yes' );
			}
		} finally {
			Claim_Lock::release( self::PURCHASE_LOCK_PREFIX . $order_id, $lock );
		}
	}

	/**
	 * Whether an order is young enough for the receipt to emit its purchase.
	 *
	 * @param string $date_created Order creation date, `Y-m-d H:i:s` in UTC (EDD 3.x).
	 * @return bool
	 */
	private function is_within_retention( string $date_created ): bool {
		// strtotime( ' UTC' ) is "now", so an empty date must be rejected before parsing.
		$created = '' === trim( $date_created ) ? false : strtotime( $date_created . ' UTC' );

		return false !== $created && $created >= time() - ( Edd_Order_Privacy::retention_days() * DAY_IN_SECONDS );
	}

	/**
	 * Whether the receipt purchase was already emitted for this order.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	protected function is_purchase_sent( int $order_id ): bool {
		return (bool) edd_get_order_meta( $order_id, self::SENT_META_KEY, true );
	}

	/**
	 * Write one order meta value through `update_metadata()`, which unslashes its input.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $key      Meta key.
	 * @param string $value    Meta value.
	 * @return void
	 */
	private function update_meta( int $order_id, string $key, string $value ): void {
		edd_update_order_meta( $order_id, $key, wp_slash( $value ) );
	}

	/**
	 * Whether the current request plausibly carries the buyer's cookies.
	 *
	 * EDD processes checkout through `admin-ajax.php` (`edd_process_checkout`) and its
	 * gateway REST routes, so admin AJAX and REST count. Cron, WP-CLI and plain admin
	 * screens (manual orders, imports) run with another person's cookies or none.
	 *
	 * @return bool
	 */
	private function is_customer_request_context(): bool {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the dispatch outcome should consume the one-shot marker.
	 *
	 * The browser push has already been printed, so a skipped dispatch (delivery off,
	 * no endpoint) still counts; only an unqueued failure stays retryable on reload.
	 *
	 * @param \CLICUTCL\Server_Side\Adapter_Result $result Dispatch result.
	 * @return bool
	 */
	private function should_mark_sent( $result ): bool {
		if ( ! is_object( $result ) ) {
			return false;
		}
		if ( ! empty( $result->skipped ) || ! empty( $result->success ) ) {
			return true;
		}

		$meta = isset( $result->meta ) && is_array( $result->meta ) ? $result->meta : array();
		return ! empty( $meta['queued'] );
	}

	/**
	 * Gather attribution saved on the order (first/last touch plus top-level keys).
	 *
	 * Never falls back to the viewer's cookie: a receipt link can be opened by someone
	 * other than the buyer.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,mixed>
	 */
	private function collect_order_attribution( int $order_id ): array {
		$attribution = array(
			'first_touch' => array(),
			'last_touch'  => array(),
		);

		foreach ( (array) edd_get_order_meta( $order_id ) as $key => $values ) {
			$key   = (string) $key;
			$value = is_array( $values ) ? reset( $values ) : $values;
			if ( 0 !== strpos( $key, '_clicutcl_' ) || ! is_scalar( $value ) || ! Woo_Order_Privacy::is_managed_meta_key( $key ) ) {
				continue;
			}

			$field = substr( $key, 10 );
			if ( in_array( $field, array( 'tracking_sent', 'consent' ), true ) ) {
				continue;
			}

			if ( 0 === strpos( $field, 'ft_' ) ) {
				$attribution['first_touch'][ substr( $field, 3 ) ] = (string) $value;
			} elseif ( 0 === strpos( $field, 'lt_' ) ) {
				$attribution['last_touch'][ substr( $field, 3 ) ] = (string) $value;
			} else {
				$attribution[ $field ] = (string) $value;
			}
		}

		return $attribution;
	}

	/**
	 * Flatten stored attribution to the `ft_*` / `lt_*` plus click-ID fields used in `dataLayer`.
	 *
	 * @param array $attribution Nested attribution from collect_order_attribution().
	 * @return array<string,string>
	 */
	private function flatten_attribution( array $attribution ): array {
		$flat = array_fill_keys(
			array_filter(
				Attribution_Provider::get_field_mapping(),
				static function ( $key ) {
					return 0 === strpos( $key, 'ft_' ) || 0 === strpos( $key, 'lt_' );
				}
			),
			''
		);

		$aliases = array(
			'utm_source'   => 'source',
			'utm_medium'   => 'medium',
			'utm_campaign' => 'campaign',
			'utm_term'     => 'term',
			'utm_content'  => 'content',
		);

		foreach ( array(
			'ft_' => 'first_touch',
			'lt_' => 'last_touch',
		) as $prefix => $touch ) {
			foreach ( $attribution[ $touch ] as $key => $value ) {
				$flat[ $prefix . ( $aliases[ $key ] ?? $key ) ] = $value;
			}
		}

		$last_touch = $attribution['last_touch'];
		foreach ( array_merge( Attribution_Provider::get_click_id_fields(), Attribution_Provider::get_browser_identifier_fields() ) as $key ) {
			// The last touch wins over a top-level click ID saved from an earlier visit.
			$value = $last_touch[ $key ] ?? ( $attribution[ $key ] ?? '' );
			if ( '' !== (string) $value ) {
				$flat[ $key ] = (string) $value;
			}
		}

		return $flat;
	}

	/**
	 * Build the purchase payload shared by `dataLayer` and server-side dispatch.
	 *
	 * @param object $order       EDD order.
	 * @param array  $attribution Nested order attribution.
	 * @return array<string,mixed>
	 */
	private function build_purchase_payload( $order, array $attribution ): array {
		$order_id = absint( $order->id );
		$commerce = $this->build_purchase_commerce( $order );

		$payload = array(
			'event_name'     => 'purchase',
			'event_id'       => 'purchase_edd_' . $order_id,
			'order_id'       => $order_id,
			'transaction_id' => $commerce['transaction_id'],
			'value'          => $commerce['value'],
			'currency'       => $commerce['currency'],
			'items'          => $commerce['items'],
			'attribution'    => $attribution,
			'identity'       => $this->resolve_purchase_identity( $order ),
			'commerce'       => $commerce,
			'meta'           => $this->build_purchase_meta( $order ),
		);

		$consent = Snapshot_V1::normalize( (string) edd_get_order_meta( $order_id, self::CONSENT_META_KEY, true ) );
		if ( ! empty( $consent ) ) {
			$payload['consent'] = $consent;
		}

		return $payload;
	}

	/**
	 * Build the nested commerce payload.
	 *
	 * @param object $order EDD order.
	 * @return array<string,mixed>
	 */
	private function build_purchase_commerce( $order ): array {
		$items         = $this->build_purchase_items( $order );
		$item_quantity = 0;
		foreach ( $items as $item ) {
			$item_quantity += $item['quantity'];
		}

		$discount_codes = array();
		foreach ( (array) $order->get_discounts() as $discount ) {
			$code = sanitize_text_field( (string) ( $discount->description ?? '' ) );
			if ( '' !== $code ) {
				$discount_codes[] = $code;
			}
		}

		$currency = sanitize_text_field( (string) $order->currency );

		return array(
			'transaction_id' => sanitize_text_field( (string) $order->get_number() ),
			'value'          => (float) $order->total,
			'currency'       => $currency,
			'subtotal'       => (float) $order->subtotal,
			'tax_total'      => (float) $order->tax,
			'shipping_total' => 0.0,
			'discount_total' => (float) $order->discount,
			'discount_codes' => $discount_codes,
			'status'         => sanitize_key( (string) $order->status ),
			'order_currency' => $currency,
			'item_quantity'  => $item_quantity,
			'items'          => $items,
		);
	}

	/**
	 * Build purchase line items.
	 *
	 * @param object $order EDD order.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_purchase_items( $order ): array {
		$items = array();

		foreach ( (array) $order->get_items() as $item ) {
			$product_id = absint( $item->product_id );
			$price_id   = isset( $item->price_id ) && is_numeric( $item->price_id ) ? absint( $item->price_id ) : null;
			$sku        = function_exists( 'edd_get_download_sku' ) ? sanitize_text_field( (string) edd_get_download_sku( $product_id ) ) : '';
			$variant    = ( null !== $price_id && function_exists( 'edd_get_price_option_name' ) )
				? sanitize_text_field( (string) edd_get_price_option_name( $product_id, $price_id, absint( $order->id ) ) )
				: '';

			$items[] = array(
				'item_id'    => $product_id,
				'item_name'  => sanitize_text_field( (string) $item->product_name ),
				'price'      => (float) $item->amount,
				'quantity'   => max( 1, absint( $item->quantity ) ),
				'product_id' => $product_id,
				// EDD reports '-' for a download without a SKU.
				'sku'        => '-' === $sku ? '' : $sku,
				'variant'    => $variant,
				'categories' => $this->get_download_categories( $product_id ),
			);
		}

		return $items;
	}

	/**
	 * Resolve download category names.
	 *
	 * @param int $product_id Download ID.
	 * @return array<int,string>
	 */
	private function get_download_categories( int $product_id ): array {
		if ( ! $product_id || ! function_exists( 'get_the_terms' ) ) {
			return array();
		}

		$terms = get_the_terms( $product_id, 'download_category' );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$names = array();
		foreach ( $terms as $term ) {
			$name = sanitize_text_field( (string) ( $term->name ?? '' ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Build order/customer metadata for the purchase payload.
	 *
	 * @param object $order EDD order.
	 * @return array<string,mixed>
	 */
	private function build_purchase_meta( $order ): array {
		$user_id             = absint( $order->user_id );
		$customer_created_at = '';
		if ( $user_id && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user && ! empty( $user->user_registered ) ) {
				$customer_created_at = gmdate( DATE_ATOM, (int) strtotime( $user->user_registered . ' UTC' ) );
			}
		}

		// EDD 3.x stores order dates in UTC.
		$created    = strtotime( (string) $order->date_created . ' UTC' );
		$created_at = $created ? gmdate( DATE_ATOM, $created ) : '';

		return array(
			'platform'            => 'edd',
			'gateway'             => sanitize_key( (string) $order->gateway ),
			'customer_id'         => absint( $order->customer_id ),
			'customer_created_at' => $customer_created_at,
			'order_created_at'    => $created_at,
		);
	}

	/**
	 * Resolve purchase identity for server-side delivery.
	 *
	 * @param object $order EDD order.
	 * @return array
	 */
	private function resolve_purchase_identity( $order ): array {
		$address = $order->get_address();
		$input   = array(
			'email'   => sanitize_email( (string) $order->email ),
			'phone'   => sanitize_text_field( (string) edd_get_order_meta( absint( $order->id ), '_edd_phone', true ) ),
			'country' => sanitize_text_field( (string) ( $address->country ?? '' ) ),
		);

		$ip = (string) $order->ip;
		if ( '' !== $ip ) {
			$input['ip'] = $ip;
		}

		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Identity_Resolver.
			$input['user_agent'] = wp_unslash( $_SERVER['HTTP_USER_AGENT'] );
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
	 * Convert the filtered purchase payload into the `dataLayer` event shape.
	 *
	 * @param array $payload   Final purchase payload.
	 * @param array $flat_attr Flat attribution fields.
	 * @return array<string,mixed>
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

		$datalayer = array_merge(
			array(
				'event'     => 'purchase',
				'ecommerce' => $ecommerce,
			),
			$flat_attr
		);

		// Ship the server event ID so a browser-side Meta Purchase dedupes against the CAPI copy.
		$event_id = isset( $payload['event_id'] ) ? sanitize_text_field( (string) $payload['event_id'] ) : '';
		if ( '' !== $event_id ) {
			$datalayer['event_id'] = $event_id;
		}

		return $datalayer;
	}
}
