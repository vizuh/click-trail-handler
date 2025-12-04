<?php

/**
 * ClickTrail WooCommerce Admin Enhancements
 * 
 * Adds attribution display features to the WooCommerce admin interface.
 */
class CLICUTCL_WooCommerce_Admin {

	public function init() {
		// Add "Source" column to orders list
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_source_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_source_column' ), 10, 2 );

		// Add attribution meta box to order edit page
		add_action( 'add_meta_boxes', array( $this, 'add_attribution_meta_box' ) );
	}

	/**
	 * Add "Source" column to orders list table
	 * 
	 * @param array $columns Existing columns
	 * @return array Modified columns
	 */
	public function add_source_column( $columns ) {
		// Insert "Source" column before "Total"
		$new_columns = array();
		
		foreach ( $columns as $key => $value ) {
			if ( 'order_total' === $key ) {
				$new_columns['clicutcl_source'] = __( 'Source', 'click-trail-handler' );
			}
			$new_columns[ $key ] = $value;
		}

		return $new_columns;
	}

	/**
	 * Render "Source" column content
	 * 
	 * @param string $column Column key
	 * @param int $post_id Order ID
	 */
	public function render_source_column( $column, $post_id ) {
		if ( 'clicutcl_source' !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			echo '—';
			return;
		}

		// Get first-touch or last-touch attribution
		$ft_source = $order->get_meta( '_clicutcl_ft_source' );
		$ft_medium = $order->get_meta( '_clicutcl_ft_medium' );

		if ( $ft_source || $ft_medium ) {
			$display = array();
			
			if ( $ft_source ) {
				$display[] = esc_html( $ft_source );
			}
			
			if ( $ft_medium ) {
				$display[] = esc_html( $ft_medium );
			}

			echo esc_html( implode( ' / ', $display ) );
		} else {
			echo '<span style="color: #999;">' . esc_html__( 'Direct', 'click-trail-handler' ) . '</span>';
		}
	}

	/**
	 * Add attribution meta box to order edit page
	 */
	public function add_attribution_meta_box() {
		add_meta_box(
			'clicutcl_attribution',
			__( 'Marketing Attribution', 'click-trail-handler' ),
			array( $this, 'render_attribution_meta_box' ),
			'shop_order',
			'side',
			'default'
		);
	}

	/**
	 * Render attribution meta box content
	 * 
	 * @param WP_Post $post Order post object
	 */
	public function render_attribution_meta_box( $post ) {
		$order = wc_get_order( $post->ID );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'No attribution data available.', 'click-trail-handler' ) . '</p>';
			return;
		}

		// Get all attribution metadata
		$attribution_fields = array(
			'ft_source'      => __( 'Source', 'click-trail-handler' ),
			'ft_medium'      => __( 'Medium', 'click-trail-handler' ),
			'ft_campaign'    => __( 'Campaign', 'click-trail-handler' ),
			'ft_term'        => __( 'Term', 'click-trail-handler' ),
			'ft_content'     => __( 'Content', 'click-trail-handler' ),
			'ft_gclid'       => __( 'Google Click ID', 'click-trail-handler' ),
			'ft_fbclid'      => __( 'Facebook Click ID', 'click-trail-handler' ),
			'ft_li_fat_id'   => __( 'LinkedIn Click ID', 'click-trail-handler' ),
			'ft_landing_page' => __( 'Landing Page', 'click-trail-handler' ),
		);

		echo '<div style="margin-bottom: 15px;">';
		echo '<h4 style="margin: 0 0 10px 0;">' . esc_html__( 'First Touch', 'click-trail-handler' ) . '</h4>';
		
		$has_data = false;
		foreach ( $attribution_fields as $key => $label ) {
			$value = $order->get_meta( '_clicutcl_' . $key );
			if ( $value ) {
				$has_data = true;
				echo '<p style="margin: 5px 0; font-size: 12px;">';
				echo '<strong>' . esc_html( $label ) . ':</strong> ';
				
				if ( 'ft_landing_page' === $key ) {
					echo '<a href="' . esc_url( $value ) . '" target="_blank" style="word-break: break-all;">' . esc_html( $value ) . '</a>';
				} else {
					echo esc_html( $value );
				}
				
				echo '</p>';
			}
		}

		if ( ! $has_data ) {
			echo '<p style="color: #999; font-size: 12px;">' . esc_html__( 'No first-touch data available', 'click-trail-handler' ) . '</p>';
		}
		
		echo '</div>';

		// Last Touch
		$lt_source = $order->get_meta( '_clicutcl_lt_source' );
		$lt_medium = $order->get_meta( '_clicutcl_lt_medium' );
		
		if ( $lt_source || $lt_medium ) {
			echo '<div>';
			echo '<h4 style="margin: 0 0 10px 0;">' . esc_html__( 'Last Touch', 'click-trail-handler' ) . '</h4>';
			
			if ( $lt_source ) {
				echo '<p style="margin: 5px 0; font-size: 12px;"><strong>' . esc_html__( 'Source', 'click-trail-handler' ) . ':</strong> ' . esc_html( $lt_source ) . '</p>';
			}
			if ( $lt_medium ) {
				echo '<p style="margin: 5px 0; font-size: 12px;"><strong>' . esc_html__( 'Medium', 'click-trail-handler' ) . ':</strong> ' . esc_html( $lt_medium ) . '</p>';
			}
			
			echo '</div>';
		}
	}
}
