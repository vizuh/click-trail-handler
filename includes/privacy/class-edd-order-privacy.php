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
 */
class Edd_Order_Privacy {
	/** Export and erase page size. */
	public const PAGE_SIZE = 50;

	/** Maximum order rows touched by one scheduled retention run. */
	public const RETENTION_BATCH_SIZE = 100;

	/**
	 * Build an EDD order meta query restricted to ClickTrail-owned keys.
	 *
	 * `WP_Meta_Query` joins the meta table once per clause, so the Woo allowlist as 130+ OR'd
	 * `EXISTS` clauses exceeds MariaDB's 61-table join limit on a real EDD order query. The same
	 * allowlist is folded into one anchored `REGEXP` on the meta key instead: exact keys must match
	 * completely, prefix families only at the start. Lookalike keys never match, and callers still
	 * re-check every order's keys with `Woo_Order_Privacy::is_managed_meta_key()` before deleting.
	 *
	 * @return array<int,array<string,string>> One-clause meta_query.
	 */
	public static function managed_meta_query(): array {
		$exact    = array();
		$prefixes = array();
		foreach ( Woo_Order_Privacy::managed_meta_query() as $clause ) {
			if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
				continue;
			}
			if ( isset( $clause['compare_key'] ) ) {
				$prefixes[] = preg_quote( (string) $clause['key'], '/' );
			} else {
				$exact[] = preg_quote( (string) $clause['key'], '/' );
			}
		}

		return array(
			array(
				'key'         => '^(' . implode( '|', $exact ) . ')$|^(' . implode( '|', $prefixes ) . ')',
				'compare_key' => 'REGEXP',
			),
		);
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
			foreach ( $this->get_order_managed_meta( $order_id ) as $meta ) {
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
	 * so the offset advances only past orders that could not be cleaned.
	 *
	 * @param int $batch_size Number of orders fetched per page.
	 * @return array{processed:int,removed:int,failed:int,remaining:bool}
	 */
	public function purge_all_order_metadata( int $batch_size = self::RETENTION_BATCH_SIZE ): array {
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
					'meta_query' => self::managed_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Explicit manual purge, batched.
				)
			);
			if ( empty( $orders ) ) {
				break;
			}

			$processed += count( $orders );
			foreach ( $orders as $order ) {
				if ( $this->remove_order_metadata( $this->order_id( $order ) ) ) {
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
	 * @param int $order_id Order ID.
	 * @return array<int,array{key:string,value:mixed}>
	 */
	private function get_order_managed_meta( int $order_id ): array {
		if ( ! $order_id || ! function_exists( 'edd_get_order_meta' ) ) {
			return array();
		}

		$rows = array();
		foreach ( (array) edd_get_order_meta( $order_id ) as $key => $values ) {
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
	 * @param int $order_id Order ID.
	 * @return bool Whether every managed key is gone afterwards.
	 */
	private function remove_order_metadata( int $order_id ): bool {
		if ( ! function_exists( 'edd_delete_order_meta' ) ) {
			return false;
		}

		foreach ( $this->get_order_managed_meta( $order_id ) as $meta ) {
			edd_delete_order_meta( $order_id, $meta['key'] );
		}

		return empty( $this->get_order_managed_meta( $order_id ) );
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
