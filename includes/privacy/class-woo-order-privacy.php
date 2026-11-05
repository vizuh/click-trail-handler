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
 * tables directly, except through the order-query `meta_query` argument.
 *
 * The one key it leaves in place is the thank-you "purchase already sent" marker
 * (`SENT_MARKER_KEY`). It holds no personal data and is the only thing that stops a
 * thank-you page revisited after retention or a subject erase from pushing the purchase
 * a second time, so subject erase, retention and the Diagnostics purge keep it. Export
 * still lists it, and only uninstall removes it.
 */
class Woo_Order_Privacy {
	/** Export and erase page size. */
	public const PAGE_SIZE = 50;

	/** Maximum order rows touched by one scheduled retention run. */
	public const RETENTION_BATCH_SIZE = 100;

	/** Meta key of the purchase-sent marker; survives subject erase, retention and the Diagnostics purge. */
	public const SENT_MARKER_KEY = '_clicutcl_tracking_sent';

	/** Exact order metadata keys written by ClickTrail. */
	private const EXACT_META_KEYS = array(
		'_clicutcl_consent',
		self::SENT_MARKER_KEY,
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
	 * Days an order's ClickTrail data is kept, as `Cleanup::run_cleanup()` measures it.
	 *
	 * The thank-you push uses this as the age past which an order no longer emits a purchase, so an
	 * order whose data retention already removed cannot be counted again. Keep it in step with the
	 * `$days` that `Cleanup` passes to `purge_expired_order_metadata()`.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		$days = class_exists( \CLICUTCL\Settings\Attribution_Settings::class )
			? ( new \CLICUTCL\Settings\Attribution_Settings() )->get_cookie_duration()
			: 90;

		return $days < 1 ? 90 : $days;
	}

	/**
	 * Exact keys and key prefixes ClickTrail owns on an order.
	 *
	 * @return array{exact:array<int,string>,prefixes:array<int,string>}
	 */
	public static function managed_key_families(): array {
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

		return array(
			'exact'    => $keys,
			'prefixes' => array( '_clicutcl_ft_', '_clicutcl_lt_', '_clicutcl_woo_milestone_sent_' ),
		);
	}

	/**
	 * Build a Woo order query restricted to ClickTrail-owned metadata.
	 *
	 * Meta queries join the meta table once per clause that is not join-compatible, and `EXISTS`
	 * clauses are not: the allowlist as ~107 OR'd `EXISTS` clauses exceeds MySQL/MariaDB's 61-table
	 * join limit on HPOS (`wc_orders_meta`), where the database error surfaced as an empty result and
	 * the eraser and retention cleanup silently processed nothing. The allowlist is folded into one
	 * anchored `meta_key` `REGEXP` instead: exact keys must match completely, prefix families only at
	 * the start, so lookalike keys never match. Callers still re-check every order's keys with
	 * `is_managed_meta_key()` before deleting.
	 *
	 * The sent marker is left out unless asked for, so an order that carries only the marker is not
	 * returned (and cannot fill a retention batch) when there is nothing left to remove.
	 *
	 * @param bool $with_sent_marker Whether the purchase-sent marker counts as a managed key.
	 * @return array<int,array<string,string>> One-clause meta_query.
	 */
	public static function managed_meta_query( bool $with_sent_marker = false ): array {
		$families = self::managed_key_families();
		$exact    = array();
		foreach ( $families['exact'] as $key ) {
			if ( $with_sent_marker || self::SENT_MARKER_KEY !== $key ) {
				$exact[] = preg_quote( $key, '/' );
			}
		}
		$prefixes = array_map(
			static function ( string $prefix ): string {
				return preg_quote( $prefix, '/' );
			},
			$families['prefixes']
		);

		return array(
			array(
				'key'         => '^(' . implode( '|', $exact ) . ')$|^(' . implode( '|', $prefixes ) . ')',
				'compare_key' => 'REGEXP',
			),
		);
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
		$families = self::managed_key_families();
		if ( in_array( $key, $families['exact'], true ) ) {
			return true;
		}
		foreach ( $families['prefixes'] as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
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
			$managed = $this->get_order_managed_meta( $order, true );
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
				'meta_query'    => self::managed_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Paged privacy export/erase for one customer's orders.
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
				'meta_query'   => self::managed_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded, date-filtered retention batch.
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
	 * The purchase-sent marker stays unless `$with_sent_marker` is set, which only uninstall does.
	 *
	 * @param int  $batch_size       Number of orders fetched per page.
	 * @param bool $with_sent_marker Also remove the purchase-sent marker.
	 * @return array{processed:int,removed:int,failed:int,remaining:bool}
	 */
	public function purge_all_order_metadata( int $batch_size = self::RETENTION_BATCH_SIZE, bool $with_sent_marker = false ): array {
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
				$managed = $this->get_order_managed_meta( $order, $with_sent_marker );
				if ( empty( $managed ) ) {
					continue;
				}
				if ( $this->remove_order_metadata( $order, $with_sent_marker ) ) {
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
	 * @param mixed $order            Woo order.
	 * @param bool  $with_sent_marker Include the purchase-sent marker.
	 * @return array<int,array{key:string,value:mixed}>
	 */
	private function get_order_managed_meta( $order, bool $with_sent_marker = false ): array {
		if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_meta_data' ) ) ) {
			return array();
		}
		return array_values(
			array_filter(
				self::managed_meta( (array) $order->get_meta_data() ),
				static function ( array $meta ) use ( $with_sent_marker ): bool {
					return $with_sent_marker || self::SENT_MARKER_KEY !== $meta['key'];
				}
			)
		);
	}

	/**
	 * Remove managed metadata from one order.
	 *
	 * @param mixed $order            Woo order.
	 * @param bool  $with_sent_marker Also remove the purchase-sent marker.
	 * @return bool Whether metadata was removed and saved.
	 */
	private function remove_order_metadata( $order, bool $with_sent_marker = false ): bool {
		if ( ! is_object( $order )
			|| ! is_callable( array( $order, 'get_meta_data' ) )
			|| ! is_callable( array( $order, 'delete_meta_data' ) )
			|| ! is_callable( array( $order, 'save' ) )
		) {
			return false;
		}
		$managed = $this->get_order_managed_meta( $order, $with_sent_marker );
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
		return 0 === count( $this->get_order_managed_meta( $order, $with_sent_marker ) );
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
