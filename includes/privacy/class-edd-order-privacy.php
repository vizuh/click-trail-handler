<?php
/**
 * Easy Digital Downloads order privacy lifecycle.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles ClickTrail-owned metadata on EDD 3.x orders through EDD's order query and meta API.
 *
 * The managed-key allowlist is the WooCommerce one on purpose: EDD orders carry the same
 * `_clicutcl_*` keys, and `Edd::save_order_attribution()` writes only keys this allowlist
 * recognizes, so everything it stores is exportable, erasable and expirable. Unrelated EDD
 * order meta (phone, tax rate, gateway ids) is never read or deleted.
 *
 * The one exception is the receipt "purchase already sent" marker (`Edd::SENT_META_KEY`). It holds
 * no personal data and is the only thing that stops a receipt revisited after retention or after
 * a subject erase from pushing the purchase and dispatching a second server copy, so subject
 * erase, retention and the Diagnostics purge leave it in place. Only uninstall removes it.
 */
class Edd_Order_Privacy {
	/** Export and erase page size. */
	public const PAGE_SIZE = 50;

	/** Maximum order rows touched by one scheduled retention run. */
	public const RETENTION_BATCH_SIZE = 100;

	/** Meta key of the purchase-sent marker; survives erase, retention and the Diagnostics purge. */
	public const SENT_MARKER_KEY = Woo_Order_Privacy::SENT_MARKER_KEY;

	/**
	 * Days an order's ClickTrail data is kept, as `Cleanup::run_cleanup()` measures it.
	 *
	 * `Edd::push_purchase_event()` uses this as the age past which a receipt no longer emits a
	 * purchase, so that an order whose data retention already removed cannot be counted again.
	 * Keep it in step with the `$days` that `Cleanup` passes to `purge_expired_order_metadata()`.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		return Woo_Order_Privacy::retention_days();
	}

	/**
	 * Build an EDD order meta query restricted to ClickTrail-owned keys.
	 *
	 * The Woo allowlist folded into one anchored `REGEXP` on the meta key (see
	 * `Woo_Order_Privacy::managed_meta_query()`), so the query stays far below MariaDB's 61-table
	 * join limit. Callers still re-check every order's keys with
	 * `Woo_Order_Privacy::is_managed_meta_key()` before deleting.
	 *
	 * @param bool $with_sent_marker Whether the purchase-sent marker counts as a managed key.
	 * @return array<int,array<string,string>> One-clause meta_query.
	 */
	public static function managed_meta_query( bool $with_sent_marker = false ): array {
		return Woo_Order_Privacy::managed_meta_query( $with_sent_marker );
	}

	/**
	 * Export ClickTrail metadata from the orders billed to an email.
	 *
	 * @param string $email Customer email.
	 * @param int    $page  Page number.
	 * @return array{items:array<int,array<string,mixed>>,done:bool}
	 */
	public function export_order_data( string $email, int $page = 1 ): array {
		$orders = $this->get_orders(
			array(
				'email'   => $email,
				'number'  => self::PAGE_SIZE,
				'offset'  => ( max( 1, $page ) - 1 ) * self::PAGE_SIZE,
				'orderby' => 'id',
				'order'   => 'DESC',
			)
		);
		$items  = array();

		foreach ( $orders as $order ) {
			$order_id = $this->order_id( $order );
			foreach ( $this->get_order_managed_meta( $order_id, true ) as $meta ) {
				$items[] = array(
					'group_id'    => 'clicutcl_edd_orders',
					'group_label' => __( 'ClickTrail Easy Digital Downloads Order Data', 'click-trail-handler' ),
					'item_id'     => 'clicutcl-edd-order-' . $order_id . '-' . sanitize_key( $meta['key'] ),
					'data'        => array(
						array(
							'name'  => __( 'Order ID', 'click-trail-handler' ),
							'value' => $order_id,
						),
						array(
							'name'  => __( 'Metadata Key', 'click-trail-handler' ),
							'value' => sanitize_text_field( $meta['key'] ),
						),
						array(
							'name'  => __( 'Metadata Value', 'click-trail-handler' ),
							'value' => $this->value_to_export( $meta['value'] ),
						),
					),
				);
			}
		}

		return array(
			'items' => $items,
			'done'  => count( $orders ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erase ClickTrail metadata from the orders billed to an email.
	 *
	 * The eraser rereads the first page on each callback: the managed-meta query drops
	 * processed orders from the result set, so advancing the offset would skip later orders.
	 *
	 * @param string $email Customer email.
	 * @param int    $page  Page number (unused, see above).
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase_order_data( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the WordPress personal data eraser callback signature.
		$orders        = $this->get_orders(
			array(
				'email'      => $email,
				'number'     => self::PAGE_SIZE,
				'offset'     => 0,
				'orderby'    => 'id',
				'order'      => 'ASC',
				'meta_query' => self::managed_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Paged privacy erase for one customer's orders.
			)
		);
		$removed       = false;
		$retained      = false;
		$managed_found = false;
		$messages      = array();

		foreach ( $orders as $order ) {
			$order_id = $this->order_id( $order );
			if ( empty( $this->get_order_managed_meta( $order_id ) ) ) {
				continue;
			}

			$managed_found = true;
			if ( $this->remove_order_metadata( $order_id ) ) {
				$removed = true;
			} else {
				$retained   = true;
				$messages[] = __( 'Some ClickTrail Easy Digital Downloads order metadata could not be deleted.', 'click-trail-handler' );
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => array_values( array_unique( $messages ) ),
			'done'           => count( $orders ) < self::PAGE_SIZE || ! $managed_found,
		);
	}

	/**
	 * Remove expired order metadata in one bounded retention batch.
	 *
	 * @param int $days       Retention period.
	 * @param int $batch_size Maximum orders to process.
	 * @return array{processed:int,removed:int,failed:int,remaining:bool}
	 */
	public function purge_expired_order_metadata( int $days, int $batch_size = self::RETENTION_BATCH_SIZE ): array {
		$days       = max( 1, $days );
		$batch_size = max( 1, $batch_size );
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$orders     = $this->get_orders(
			array(
				'date_created_query' => array(
					'column' => 'date_created',
					array( 'before' => $cutoff ),
				),
				'number'             => $batch_size + 1,
				'orderby'            => 'date_created',
				'order'              => 'ASC',
				'meta_query'         => self::managed_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded, date-filtered retention batch.
			)
		);
		$remaining  = count( $orders ) > $batch_size;
		$orders     = array_slice( $orders, 0, $batch_size );
		$removed    = 0;
		$failed     = 0;

		foreach ( $orders as $order ) {
			$order_id = $this->order_id( $order );
			if ( empty( $this->get_order_managed_meta( $order_id ) ) ) {
				continue;
			}
			if ( $this->remove_order_metadata( $order_id ) ) {
				++$removed;
			} else {
				++$failed;
			}
		}

		return array(
			'processed' => count( $orders ),
			'removed'   => $removed,
			'failed'    => $failed,
			'remaining' => $remaining || $failed > 0,
		);
	}

	/**
	 * Remove ClickTrail metadata from all orders for an explicit manual purge.
	 *
	 * Only orders that carry managed meta are visited. Cleaned orders leave the result set,
	 * so the offset advances only past orders that could not be cleaned. The purchase-sent
	 * marker stays unless `$with_sent_marker` is set, which only uninstall does.
	 *
	 * @param int  $batch_size       Number of orders fetched per page.
	 * @param bool $with_sent_marker Also remove the purchase-sent marker.
	 * @return array{processed:int,removed:int,failed:int,remaining:bool}
	 */
	public function purge_all_order_metadata( int $batch_size = self::RETENTION_BATCH_SIZE, bool $with_sent_marker = false ): array {
		$batch_size = max( 1, $batch_size );
		$processed  = 0;
		$removed    = 0;
		$failed     = 0;

		while ( true ) {
			$orders = $this->get_orders(
				array(
					'number'     => $batch_size,
					'offset'     => $failed,
					'orderby'    => 'id',
					'order'      => 'ASC',
					'meta_query' => self::managed_meta_query( $with_sent_marker ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Explicit manual purge, batched.
				)
			);
			if ( empty( $orders ) ) {
				break;
			}

			$processed += count( $orders );
			foreach ( $orders as $order ) {
				if ( $this->remove_order_metadata( $this->order_id( $order ), $with_sent_marker ) ) {
					++$removed;
				} else {
					++$failed;
				}
			}

			if ( count( $orders ) < $batch_size ) {
				break;
			}
		}

		return array(
			'processed' => $processed,
			'removed'   => $removed,
			'failed'    => $failed,
			'remaining' => $failed > 0,
		);
	}

	/**
	 * Return managed metadata of one order.
	 *
	 * @param int  $order_id         Order ID.
	 * @param bool $with_sent_marker Include the purchase-sent marker.
	 * @return array<int,array{key:string,value:mixed}>
	 */
	private function get_order_managed_meta( int $order_id, bool $with_sent_marker = false ): array {
		if ( ! $order_id || ! function_exists( 'edd_get_order_meta' ) ) {
			return array();
		}

		$rows = array();
		foreach ( (array) edd_get_order_meta( $order_id ) as $key => $values ) {
			if ( ! $with_sent_marker && self::SENT_MARKER_KEY === (string) $key ) {
				continue;
			}
			$rows[] = array(
				'key'   => (string) $key,
				'value' => is_array( $values ) ? reset( $values ) : $values,
			);
		}

		return Woo_Order_Privacy::managed_meta( $rows );
	}

	/**
	 * Remove managed metadata from one order.
	 *
	 * @param int  $order_id         Order ID.
	 * @param bool $with_sent_marker Also remove the purchase-sent marker.
	 * @return bool Whether every targeted managed key is gone afterwards.
	 */
	private function remove_order_metadata( int $order_id, bool $with_sent_marker = false ): bool {
		if ( ! function_exists( 'edd_delete_order_meta' ) ) {
			return false;
		}

		foreach ( $this->get_order_managed_meta( $order_id, $with_sent_marker ) as $meta ) {
			edd_delete_order_meta( $order_id, $meta['key'] );
		}

		return empty( $this->get_order_managed_meta( $order_id, $with_sent_marker ) );
	}

	/**
	 * Fetch orders through EDD's public query API.
	 *
	 * @param array<string,mixed> $args edd_get_orders arguments.
	 * @return array<int,mixed>
	 */
	private function get_orders( array $args ): array {
		if ( ! function_exists( 'edd_get_orders' ) ) {
			return array();
		}

		$orders = edd_get_orders( $args );
		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Read an order row's ID.
	 *
	 * @param mixed $order EDD order row.
	 * @return int
	 */
	private function order_id( $order ): int {
		return is_object( $order ) ? absint( $order->id ) : 0;
	}

	/**
	 * Serialize one value without exposing non-scalar structures as PHP data.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private function value_to_export( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			$json = wp_json_encode( $value );
			return is_string( $json ) ? $json : '';
		}
		$value = (string) $value;
		return function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $value ) : trim( $value );
	}
}
