<?php
/**
 * Privacy Handler
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers WordPress personal data exporter and eraser callbacks.
 */
class Privacy_Handler {

	/**
	 * Export/erase page size.
	 */
	private const PAGE_SIZE = 50;

	/**
	 * Related transient keys that may contain debug/event snapshots.
	 */
	private const RELATED_TRANSIENT_KEYS = array(
		'clicutcl_dispatch_buffer',
		'clicutcl_v2_events_buffer',
		'clicutcl_last_error',
	);

	/**
	 * Related option keys that may contain debug/event snapshots.
	 */
	private const RELATED_OPTION_KEYS = array(
		'clicutcl_dispatch_log',
		'clicutcl_last_error',
		'clicutcl_attempts',
	);

	/**
	 * Register privacy hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Register exporter callback.
	 *
	 * @param array<string,array<string,mixed>> $exporters Exporters list.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['clicutcl'] = array(
			'exporter_friendly_name' => __( 'ClickTrail Tracking Data', 'click-trail-handler' ),
			'callback'               => array( $this, 'export_user_data' ),
		);

		return $exporters;
	}

	/**
	 * Register eraser callback.
	 *
	 * @param array<string,array<string,mixed>> $erasers Erasers list.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['clicutcl'] = array(
			'eraser_friendly_name' => __( 'ClickTrail Tracking Data', 'click-trail-handler' ),
			'callback'             => array( $this, 'erase_user_data' ),
		);

		return $erasers;
	}

	/**
	 * Export tracked data for a specific email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export_user_data( string $email, int $page = 1 ): array {
		global $wpdb;

		$page = max( 1, $page );

		if ( ! is_email( $email ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$table = $this->get_events_table_name();
		if ( ! $this->table_exists( $table ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$table_escaped = esc_sql( $table );

		$user_id = (int) email_exists( $email );
		$where   = $this->build_event_match_where( $email, $user_id );
		$offset  = ( $page - 1 ) * self::PAGE_SIZE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and WHERE clause are plugin-owned.
		$query = "SELECT id, event_type, event_data, created_at FROM {$table} WHERE {$where['sql']} ORDER BY created_at DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Required for privacy export callbacks.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Params merged dynamically via array_merge.
			$wpdb->prepare(
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query built above.
				array_merge( $where['params'], array( self::PAGE_SIZE, $offset ) )
			),
			ARRAY_A
		);

		$export_items = array();

		foreach ( $rows as $row ) {
			$raw_data = $this->normalize_event_data_for_export( $row['event_data'] ?? '' );

			$export_items[] = array(
				'group_id'    => 'clicutcl_events',
				'group_label' => __( 'ClickTrail Events', 'click-trail-handler' ),
				'item_id'     => 'clicutcl-event-' . absint( $row['id'] ?? 0 ),
				'data'        => array(
					array(
						'name'  => __( 'Event Type', 'click-trail-handler' ),
						'value' => sanitize_text_field( (string) ( $row['event_type'] ?? '' ) ),
					),
					array(
						'name'  => __( 'Date', 'click-trail-handler' ),
						'value' => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
					),
					array(
						'name'  => __( 'Event Data', 'click-trail-handler' ),
						'value' => $raw_data,
					),
				),
			);
		}

		if ( 1 === $page ) {
			$export_items = array_merge(
				$export_items,
				$this->export_related_storage_items( $email, $user_id )
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erase tracked data for a specific email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase_user_data( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the WordPress personal data eraser callback signature.
		global $wpdb;

		if ( ! is_email( $email ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$table = $this->get_events_table_name();
		if ( ! $this->table_exists( $table ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$table_escaped = esc_sql( $table );

		$user_id = (int) email_exists( $email );
		$where   = $this->build_event_match_where( $email, $user_id );

		// Always read first page for erasure to avoid offset skipping after deletions.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and WHERE clause are plugin-owned.
		$query = "SELECT id FROM {$table_escaped} WHERE {$where['sql']} ORDER BY id ASC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Required for privacy erasure callbacks.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query built above.
				array_merge( $where['params'], array( self::PAGE_SIZE ) )
			),
			ARRAY_A
		);

		$removed_any = false;
		$retained    = false;
		$messages    = array();

		$event_ids = array_values(
			array_filter(
				array_map(
					static function ( array $row ): int {
						return absint( $row['id'] ?? 0 );
					},
					$rows
				)
			)
		);

		if ( ! empty( $event_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholder count are plugin-owned.
			$delete_query = "DELETE FROM {$table_escaped} WHERE id IN ({$placeholders})";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for privacy erasure callbacks.
			$deleted = $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder list assembled to match the selected IDs.
				$wpdb->prepare( $delete_query, $event_ids )
			);

			if ( false !== $deleted && $deleted > 0 ) {
				$removed_any = true;
				if ( $deleted < count( $event_ids ) ) {
					$retained   = true;
					$messages[] = __( 'Some ClickTrail event records could not be deleted.', 'click-trail-handler' );
				}
			} elseif ( ! empty( $rows ) ) {
				$retained   = true;
				$messages[] = __( 'Some ClickTrail event records could not be deleted.', 'click-trail-handler' );
			}

			if ( $retained && defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $wpdb->last_error ) ) {
				// translators: %s: database error message.
				$messages[] = sprintf( __( 'Database error: %s', 'click-trail-handler' ), sanitize_text_field( $wpdb->last_error ) );
			}
		}

		$related = $this->erase_related_storage( $email, $user_id );
		if ( ! empty( $related['items_removed'] ) ) {
			$removed_any = true;
		}
		if ( ! empty( $related['items_retained'] ) ) {
			$retained = true;
		}
		if ( ! empty( $related['messages'] ) && is_array( $related['messages'] ) ) {
			$messages = array_merge( $messages, $related['messages'] );
		}

		return array(
			'items_removed'  => $removed_any,
			'items_retained' => $retained,
			'messages'       => array_values( array_unique( $messages ) ),
			'done'           => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Build table name.
	 *
	 * @return string
	 */
	private function get_events_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'clicutcl_events';
	}

	/**
	 * Check if table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight existence check.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return is_string( $found ) && $found === $table;
	}

	/**
	 * Build WHERE clause/params to match user-linked event records.
	 *
	 * Event table currently stores JSON in `event_data`, so matching is best-effort:
	 * - legacy numeric user id (`"user_id":123` or `"user_id":"123"`),
	 * - email values,
	 * - salted user hash used by current tracking flows.
	 *
	 * @param string $email   Email address.
	 * @param int    $user_id WordPress user ID (if found).
	 * @return array{sql:string,params:array<int,string>}
	 */
	private function build_event_match_where( string $email, int $user_id ): array {
		$params = array(
			'%' . $this->escape_like( $email ) . '%',
		);

		$clauses = array(
			'event_data LIKE %s',
		);

		if ( $user_id > 0 ) {
			$user_id_raw   = '%' . $this->escape_like( '"user_id":' . $user_id ) . '%';
			$user_id_str   = '%' . $this->escape_like( '"user_id":"' . $user_id . '"' ) . '%';
			$user_hash     = hash( 'sha256', (string) $user_id . wp_salt( 'auth' ) );
			$user_hash_raw = '%' . $this->escape_like( '"user_hash":"' . $user_hash . '"' ) . '%';

			$clauses[] = 'event_data LIKE %s';
			$clauses[] = 'event_data LIKE %s';
			$clauses[] = 'event_data LIKE %s';

			$params[] = $user_id_raw;
			$params[] = $user_id_str;
			$params[] = $user_hash_raw;
		}

		return array(
			'sql'    => '( ' . implode( ' OR ', $clauses ) . ' )',
			'params' => $params,
		);
	}

	/**
	 * Normalize raw JSON event payload for export.
	 *
	 * @param string $event_data Raw event payload.
	 * @return string
	 */
	private function normalize_event_data_for_export( string $event_data ): string {
		$decoded = json_decode( $event_data, true );

		if ( is_array( $decoded ) ) {
			$json = wp_json_encode( $decoded );
			return is_string( $json ) ? $json : '';
		}

		return sanitize_textarea_field( $event_data );
	}

	/**
	 * Export matching entries from related debug options/transients.
	 *
	 * @param string $email   Email address.
	 * @param int    $user_id WordPress user ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_related_storage_items( string $email, int $user_id ): array {
		$markers = $this->build_personal_markers( $email, $user_id );
		$items   = array();

		foreach ( self::RELATED_TRANSIENT_KEYS as $key ) {
			$stored = get_transient( $key );
			if ( false === $stored || ! $this->value_matches_markers( $stored, $markers ) ) {
				continue;
			}

			$items[] = array(
				'group_id'    => 'clicutcl_debug_storage',
				'group_label' => __( 'ClickTrail Debug Storage', 'click-trail-handler' ),
				'item_id'     => 'transient-' . sanitize_key( $key ),
				'data'        => array(
					array(
						'name'  => __( 'Storage Type', 'click-trail-handler' ),
						'value' => 'transient',
					),
					array(
						'name'  => __( 'Storage Key', 'click-trail-handler' ),
						'value' => sanitize_text_field( $key ),
					),
					array(
						'name'  => __( 'Data Snapshot', 'click-trail-handler' ),
						'value' => $this->value_to_snapshot( $stored ),
					),
				),
			);
		}

		$missing = new \stdClass();
		foreach ( self::RELATED_OPTION_KEYS as $key ) {
			$stored = get_option( $key, $missing );
			if ( $missing === $stored || ! $this->value_matches_markers( $stored, $markers ) ) {
				continue;
			}

			$items[] = array(
				'group_id'    => 'clicutcl_debug_storage',
				'group_label' => __( 'ClickTrail Debug Storage', 'click-trail-handler' ),
				'item_id'     => 'option-' . sanitize_key( $key ),
				'data'        => array(
					array(
						'name'  => __( 'Storage Type', 'click-trail-handler' ),
						'value' => 'option',
					),
					array(
						'name'  => __( 'Storage Key', 'click-trail-handler' ),
						'value' => sanitize_text_field( $key ),
					),
					array(
						'name'  => __( 'Data Snapshot', 'click-trail-handler' ),
						'value' => $this->value_to_snapshot( $stored ),
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Erase matching records from related debug options/transients.
	 *
	 * @param string $email   Email address.
	 * @param int    $user_id WordPress user ID.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>}
	 */
	private function erase_related_storage( string $email, int $user_id ): array {
		$markers     = $this->build_personal_markers( $email, $user_id );
		$removed_any = false;
		$retained    = false;
		$messages    = array();

		foreach ( self::RELATED_TRANSIENT_KEYS as $key ) {
			$stored = get_transient( $key );
			if ( false === $stored || ! $this->value_matches_markers( $stored, $markers ) ) {
				continue;
			}

			if ( delete_transient( $key ) ) {
				$removed_any = true;
			} else {
				$retained   = true;
				$messages[] = __( 'Some ClickTrail transient debug records could not be deleted.', 'click-trail-handler' );
			}
		}

		$missing = new \stdClass();
		foreach ( self::RELATED_OPTION_KEYS as $key ) {
			$stored = get_option( $key, $missing );
			if ( $missing === $stored || ! $this->value_matches_markers( $stored, $markers ) ) {
				continue;
			}

			if ( is_array( $stored ) ) {
				$changed        = false;
				$removed_record = false;
				$scrubbed       = $this->scrub_storage_data( $stored, $markers, $changed, $removed_record );

				if ( ! $changed ) {
					continue;
				}

				$updated = empty( $scrubbed ) ? delete_option( $key ) : update_option( $key, $scrubbed, false );
				if ( $updated ) {
					if ( $removed_record ) {
						$removed_any = true;
					}
				} else {
					$retained   = true;
					$messages[] = __( 'Some ClickTrail option debug records could not be updated.', 'click-trail-handler' );
				}
				continue;
			}

			if ( delete_option( $key ) ) {
				$removed_any = true;
			} else {
				$retained   = true;
				$messages[] = __( 'Some ClickTrail option debug records could not be deleted.', 'click-trail-handler' );
			}
		}

		return array(
			'items_removed'  => $removed_any,
			'items_retained' => $retained,
			'messages'       => array_values( array_unique( $messages ) ),
		);
	}

	/**
	 * Build marker strings used to detect user-linked records.
	 *
	 * @param string $email   Email address.
	 * @param int    $user_id WordPress user ID.
	 * @return array<int,string>
	 */
	private function build_personal_markers( string $email, int $user_id ): array {
		$markers = array( strtolower( $email ) );

		if ( $user_id > 0 ) {
			$user_hash = hash( 'sha256', (string) $user_id . wp_salt( 'auth' ) );
			$markers[] = strtolower( $user_hash );
			$markers[] = strtolower( '"user_id":' . $user_id );
			$markers[] = strtolower( '"user_id":"' . $user_id . '"' );
			$markers[] = strtolower( '"user_hash":"' . $user_hash . '"' );
		}

		return array_values( array_unique( $markers ) );
	}

	/**
	 * Detect whether a value contains any user marker.
	 *
	 * @param mixed             $value   Stored value.
	 * @param array<int,string> $markers Marker strings.
	 * @return bool
	 */
	private function value_matches_markers( $value, array $markers ): bool {
		$haystack = '';
		if ( is_array( $value ) || is_object( $value ) ) {
			$json     = wp_json_encode( $value );
			$haystack = is_string( $json ) ? $json : '';
		} else {
			$haystack = (string) $value;
		}

		if ( '' === $haystack ) {
			return false;
		}

		$haystack = strtolower( $haystack );
		foreach ( $markers as $marker ) {
			if ( '' !== $marker && false !== strpos( $haystack, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scrub matching entries from option data arrays.
	 *
	 * @param array             $value          Option value.
	 * @param array<int,string> $markers        Marker strings.
	 * @param bool              $changed        Whether the array changed.
	 * @param bool              $removed_record Whether any record was removed.
	 * @return array
	 */
	private function scrub_storage_data( array $value, array $markers, bool &$changed, bool &$removed_record ): array {
		$changed        = false;
		$removed_record = false;

		if ( $this->is_list_array( $value ) ) {
			$filtered = array();
			foreach ( $value as $row ) {
				if ( $this->value_matches_markers( $row, $markers ) ) {
					$changed        = true;
					$removed_record = true;
					continue;
				}
				$filtered[] = $row;
			}

			return $filtered;
		}

		if ( $this->value_matches_markers( $value, $markers ) ) {
			$changed        = true;
			$removed_record = true;
			return array();
		}

		return $value;
	}

	/**
	 * Determine whether an array is a zero-based list.
	 *
	 * @param array $value Input array.
	 * @return bool
	 */
	private function is_list_array( array $value ): bool {
		$expected = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}

	/**
	 * Convert storage values to safe export snapshots.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private function value_to_snapshot( $value ): string {
		$text = '';
		if ( is_array( $value ) || is_object( $value ) ) {
			$json = wp_json_encode( $value );
			$text = is_string( $json ) ? $json : '';
		} else {
			$text = sanitize_textarea_field( (string) $value );
		}

		if ( strlen( $text ) > 800 ) {
			$text = substr( $text, 0, 800 ) . '...';
		}

		return $text;
	}

	/**
	 * Escape wildcard characters for LIKE patterns.
	 *
	 * @param string $value Value to escape.
	 * @return string
	 */
	private function escape_like( string $value ): string {
		global $wpdb;
		return $wpdb->esc_like( $value );
	}
}
