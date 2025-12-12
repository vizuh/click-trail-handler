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
		$per_page   = 20;
		$columns    = $this->get_columns();
		$hidden     = array();
		$sortable   = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		// Pagination
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// Sorting
		$orderby = ( isset( $_GET['orderby'] ) ) ? sanitize_sql_orderby( $_GET['orderby'] ) : 'created_at';
		$order   = ( isset( $_GET['order'] ) ) ? sanitize_text_field( $_GET['order'] ) : 'DESC';

		// Count total items
		$total_items = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );

		// Fetch items
		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name ORDER BY $orderby $order LIMIT %d OFFSET %d",
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
		_e( 'No logs found.', 'click-trail-handler' );
	}
}
