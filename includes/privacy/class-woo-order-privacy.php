<?php
/**
 * WooCommerce order privacy lifecycle.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Privacy;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles ClickTrail-owned metadata through WooCommerce's order CRUD API.
 *
 * Using wc_get_orders()/WC_Order is intentional: the same calls work with
 * classic post-backed orders and HPOS orders without reaching into storage
 * tables directly.
 */
class Woo_Order_Privacy {
	/** Export and erase page size. */
	public const PAGE_SIZE = 50;

	/** Maximum order rows touched by one scheduled retention run. */
	public const RETENTION_BATCH_SIZE = 100;

	/** Exact order metadata keys written by ClickTrail. */
	private const EXACT_META_KEYS = array(
		'_clicutcl_consent',
		'_clicutcl_tracking_sent',
		'_clicutcl_woo_trace_snapshot',
		'_clicutcl_visitor_id',
		'_clicutcl_session_id',
		'_clicutcl_session_count',
		'_clicutcl_session_number',
		'_clicutcl_trail_id',
	);

	/** Fallback attribution keys for uninstall runs without the plugin autoloader. */
	private const FALLBACK_ATTRIBUTION_META_KEYS = array(
		'_clicutcl_source',
		'_clicutcl_medium',
		'_clicutcl_campaign',
		'_clicutcl_term',
		'_clicutcl_content',
		'_clicutcl_utm_id',
		'_clicutcl_utm_source_platform',
		'_clicutcl_utm_creative_format',
		'_clicutcl_utm_marketing_tactic',
		'_clicutcl_channel',
		'_clicutcl_gclid',
		'_clicutcl_fbclid',
		'_clicutcl_msclkid',
		'_clicutcl_ttclid',
		'_clicutcl_wbraid',
		'_clicutcl_gbraid',
		'_clicutcl_twclid',
		'_clicutcl_li_fat_id',
		'_clicutcl_sccid',
		'_clicutcl_epik',
		'_clicutcl_rdt_cid',
		'_clicutcl_pin_cid',
		'_clicutcl_snap_cid',
		'_clicutcl_mc_cid',
		'_clicutcl_mc_eid',
		'_clicutcl_dclid',
		'_clicutcl_sc_click_id',
		'_clicutcl_fbc',
		'_clicutcl_fbp',
		'_clicutcl_ttp',
		'_clicutcl_li_gc',
		'_clicutcl_ga_client_id',
		'_clicutcl_ga_session_id',
		'_clicutcl_ga_session_number',
	);

	/**
	 * Build a Woo order query restricted to ClickTrail-owned metadata.
	 *
	 * @return array<string|int,mixed> Woo meta_query clauses.
	 */
	public static function managed_meta_query(): array {
		$keys = self::EXACT_META_KEYS;
		foreach ( self::FALLBACK_ATTRIBUTION_META_KEYS as $key ) {
			if ( ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		}
		if ( class_exists( Attribution_Provider::class ) ) {
			foreach ( Attribution_Provider::get_field_mapping() as $field ) {
				$key = '_clicutcl_' . sanitize_key( (string) $field );
				if ( ! in_array( $key, $keys, true ) ) {
					$keys[] = $key;
				}
			}
		}
		$query = array( 'relation' => 'OR' );
		foreach ( $keys as $key ) {
			$query[] = array(
				'key'     => $key,
				'compare' => 'EXISTS',
			);
		}
		foreach ( array( '_clicutcl_ft_', '_clicutcl_lt_', '_clicutcl_woo_milestone_sent_' ) as $prefix ) {
			$query[] = array(
				'key'         => $prefix,
				'compare_key' => 'LIKE',
				'compare'     => 'EXISTS',
			);
		}
		return $query;
	}

	/**
	 * Determine whether a metadata key belongs to ClickTrail's Woo surface.
	 *
	 * Attribution fields are derived from the single source-of-truth field
	 * mapping. The two prefixes cover legacy/new first- and last-touch fields
	 * without granting deletion access to unrelated order metadata.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	public static function is_managed_meta_key( string $key ): bool {
		if ( in_array( $key, self::EXACT_META_KEYS, true ) ) {
			return true;
		}

		if ( 0 === strpos( $key, '_clicutcl_ft_' )
			|| 0 === strpos( $key, '_clicutcl_lt_' )
			|| 0 === strpos( $key, '_clicutcl_woo_milestone_sent_' )
		) {
			return true;
		}

		if ( in_array( $key, self::FALLBACK_ATTRIBUTION_META_KEYS, true ) ) {
			return true;
		}

		if ( class_exists( Attribution_Provider::class ) ) {
			foreach ( Attribution_Provider::get_field_mapping() as $field ) {
				if ( '_clicutcl_' . sanitize_key( (string) $field ) === $key ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Return managed metadata from a Woo metadata list.
	 *
	 * @param array $meta_data WC_Meta_Data objects or test doubles.
	 * @return array<int,array{key:string,value:mixed}>
	 */
	public static function managed_meta( array $meta_data ): array {
		$managed = array();
		foreach ( $meta_data as $meta ) {
			$key   = '';
			$value = null;
			if ( is_object( $meta ) ) {
				$key   = isset( $meta->key ) ? (string) $meta->key : '';
				$value = $meta->value ?? null;
			} elseif ( is_array( $meta ) ) {
				$key   = isset( $meta['key'] ) ? (string) $meta['key'] : '';
				$value = $meta['value'] ?? null;
			}

			if ( self::is_managed_meta_key( $key ) ) {
				$managed[] = array(
					'key'   => $key,
					'value' => $value,
				);
			}
		}

		return $managed;
	}

	/**
	 * Export ClickTrail metadata from matching Woo orders.
	 *
	 * @param string $email Customer email.
	 * @param int    $page Page number.
	 * @return array{items:array<int,array<string,mixed>>,done:bool}
	 */
	public function export_order_data( string $email, int $page = 1 ): array {
		$orders = $this->get_orders(
			array(
				'billing_email' => $email,
				'limit'         => self::PAGE_SIZE,
				'page'          => max( 1, $page ),
				'orderby'       => 'date',
				'order'         => 'DESC',
				'return'        => 'objects',
			)
		);
		$items  = array();

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_meta_data' ) ) ) {
				continue;
			}
			$managed = $this->get_order_managed_meta( $order );
			if ( empty( $managed ) ) {
				continue;
			}

			$order_id = is_callable( array( $order, 'get_id' ) ) ? absint( $order->get_id() ) : 0;
			foreach ( $managed as $meta ) {
				$items[] = array(
					'group_id'    => 'clicutcl_woocommerce_orders',
					'group_label' => __( 'ClickTrail WooCommerce Order Data', 'click-trail-handler' ),
					'item_id'     => 'clicutcl-woo-order-' . $order_id . '-' . sanitize_key( $meta['key'] ),
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
	 * Erase ClickTrail metadata from matching Woo orders.
	 *
	 * The eraser rereads page one on each callback. The managed-meta query removes
	 * processed orders from the result set, so this prevents later orders from
	 * being skipped after metadata deletion.
	 *
	 * @param string $email Customer email.
	 * @param int    $page Page number.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase_order_data( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the WordPress personal data eraser callback signature.
		$orders        = $this->get_orders(
			array(
				'billing_email' => $email,
				'limit'         => self::PAGE_SIZE,
				'page'          => 1,
				'orderby'       => 'id',
				'order'         => 'ASC',
				'return'        => 'objects',
				'meta_query'    => self::managed_meta_query(),
			)
		);
		$removed       = false;
		$retained      = false;
		$managed_found = false;
		$messages      = array();

		foreach ( $orders as $order ) {
			if ( ! is_object( $order )
				|| ! is_callable( array( $order, 'get_meta_data' ) )
				|| ! is_callable( array( $order, 'delete_meta_data' ) )
				|| ! is_callable( array( $order, 'save' ) )
			) {
				continue;
			}
			$managed = $this->get_order_managed_meta( $order );
			if ( ! empty( $managed ) ) {
				$managed_found = true;
			}
			if ( empty( $managed ) ) {
				continue;
			}

			foreach ( $managed as $meta ) {
				$order->delete_meta_data( $meta['key'] );
			}
			try {
				$saved = $order->save();
			} catch ( \Throwable $exception ) {
				$saved = false;
			}
			if ( false === $saved ) {
				$retained   = true;
				$messages[] = __( 'Some ClickTrail WooCommerce order metadata could not be deleted.', 'click-trail-handler' );
				continue;
			}

			$remaining = $this->get_order_managed_meta( $order );
			if ( ! empty( $remaining ) ) {
				$retained   = true;
				$messages[] = __( 'Some ClickTrail WooCommerce order metadata could not be deleted.', 'click-trail-handler' );
			} else {
				$removed = true;
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
	 * @param int $days Retention period.
	 * @param int $batch_size Maximum orders to process.
	 * @return array{processed:int,removed:int,failed:int,remaining:bool}
	 */
	public function purge_expired_order_metadata( int $days, int $batch_size = self::RETENTION_BATCH_SIZE ): array {
		$days       = max( 1, $days );
		$batch_size = max( 1, $batch_size );
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$orders     = $this->get_orders(
			array(
				'date_created' => '<' . $cutoff,
				'limit'        => $batch_size + 1,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'objects',
				'meta_query'   => self::managed_meta_query(),
			)
		);
		$remaining  = count( $orders ) > $batch_size;
		$orders     = array_slice( $orders, 0, $batch_size );
		$removed    = 0;
		$failed     = 0;

		foreach ( $orders as $order ) {
			$managed = $this->get_order_managed_meta( $order );
			if ( empty( $managed ) ) {
				continue;
			}
			if ( $this->remove_order_metadata( $order ) ) {
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
	 * @param int $batch_size Number of orders fetched per page.
	 * @return array{processed:int,removed:int,failed:int,remaining:bool}
	 */
	public function purge_all_order_metadata( int $batch_size = self::RETENTION_BATCH_SIZE ): array {
		$batch_size = max( 1, $batch_size );
		$offset     = 0;
		$processed  = 0;
		$removed    = 0;
		$failed     = 0;

		while ( true ) {
			$orders = $this->get_orders(
				array(
					'limit'   => $batch_size,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
					'return'  => 'objects',
				)
			);
			if ( empty( $orders ) ) {
				break;
			}
			$processed += count( $orders );
			foreach ( $orders as $order ) {
				$managed = $this->get_order_managed_meta( $order );
				if ( empty( $managed ) ) {
					continue;
				}
				if ( $this->remove_order_metadata( $order ) ) {
					++$removed;
				} else {
					++$failed;
				}
			}
			$offset += count( $orders );
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
	 * Return managed metadata for an order-like object.
	 *
	 * @param mixed $order Woo order.
	 * @return array<int,array{key:string,value:mixed}>
	 */
	private function get_order_managed_meta( $order ): array {
		if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_meta_data' ) ) ) {
			return array();
		}
		return self::managed_meta( (array) $order->get_meta_data() );
	}

	/**
	 * Remove managed metadata from one order.
	 *
	 * @param mixed $order Woo order.
	 * @return bool Whether metadata was removed and saved.
	 */
	private function remove_order_metadata( $order ): bool {
		if ( ! is_object( $order )
			|| ! is_callable( array( $order, 'get_meta_data' ) )
			|| ! is_callable( array( $order, 'delete_meta_data' ) )
			|| ! is_callable( array( $order, 'save' ) )
		) {
			return false;
		}
		$managed = $this->get_order_managed_meta( $order );
		if ( empty( $managed ) ) {
			return false;
		}
		foreach ( $managed as $meta ) {
			$order->delete_meta_data( $meta['key'] );
		}
		try {
			$saved = $order->save();
			if ( false === $saved ) {
				return false;
			}
		} catch ( \Throwable $exception ) {
			return false;
		}
		return 0 === count( $this->get_order_managed_meta( $order ) );
	}

	/**
	 * Fetch Woo orders through the public CRUD API.
	 *
	 * @param array<string,mixed> $args wc_get_orders arguments.
	 * @return array<int,mixed>
	 */
	private function get_orders( array $args ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders( $args );
		return is_array( $orders ) ? $orders : array();
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
