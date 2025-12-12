<?php
/**
 * Log List Table
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Log_List_Table
 */
class Log_List_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'log',
				'plural'   => 'logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'clicutcl_events';
		$table_name_escaped = esc_sql( $table_name ); // Internal table name.

		$per_page   = 20;
		$columns    = $this->get_columns();
		$hidden     = array();
		$sortable   = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		// Pagination
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// Sorting
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List table sorting only, no state change.
		$orderby_raw = isset( $_GET['orderby'] ) ? wp_unslash( $_GET['orderby'] ) : 'created_at';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List table sorting only, no state change.
		$order_raw   = isset( $_GET['order'] ) ? wp_unslash( $_GET['order'] ) : 'DESC';
		
		$valid_orderby = array( 'id', 'created_at', 'event_type' );
		$orderby       = in_array( $orderby_raw, $valid_orderby, true ) ? $orderby_raw : 'created_at';
		$order         = ( 'ASC' === strtoupper( $order_raw ) ) ? 'ASC' : 'DESC';

		// Count total items
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only count query on plugin-owned table; negligible load, no separate caching needed.
		$total_items = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name_escaped}" );

		// Fetch items
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only paginated read from plugin-owned table; values are prepared and identifiers are whitelisted.
		$sql = "SELECT * FROM {$table_name_escaped} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Get columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'created_at' => __( 'Date', 'click-trail-handler' ),
			'event_type' => __( 'Event Type', 'click-trail-handler' ),
			'details'    => __( 'Details', 'click-trail-handler' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ), // True means already sorted
			'event_type' => array( 'event_type', false ),
		);
	}

	/**
	 * Column: checkbox
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="log[]" value="%s" />',
			$item['id']
		);
	}

	/**
	 * Column: created_at
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		return $item['created_at'];
	}

	/**
	 * Column: event_type
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_event_type( $item ) {
		return esc_html( $item['event_type'] );
	}

	/**
	 * Column: details
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	protected function column_details( $item ) {
		$data = json_decode( $item['event_data'], true );
		if ( ! $data ) {
			return '-';
		}

		$output = [];
		
		if ( isset( $data['wa_href'] ) ) {
			$output[] = '<strong>Link:</strong> ' . esc_url( $data['wa_href'] );
		}
		
		if ( isset( $data['attribution'] ) && is_array( $data['attribution'] ) ) {
			$attr = $data['attribution'];
			if ( ! empty( $attr['ft_source'] ) ) {
				$output[] = '<strong>Source:</strong> ' . esc_html( $attr['ft_source'] );
			}
			if ( ! empty( $attr['ft_medium'] ) ) {
				$output[] = '<strong>Medium:</strong> ' . esc_html( $attr['ft_medium'] );
			}
			if ( ! empty( $attr['ft_campaign'] ) ) {
				$output[] = '<strong>Campaign:</strong> ' . esc_html( $attr['ft_campaign'] );
			}
		}

		return implode( '<br>', $output );
	}

	/**
	 * No items found message.
	 */
	public function no_items() {
		esc_html_e( 'No logs found.', 'click-trail-handler' );
	}
}
