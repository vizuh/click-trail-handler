<?php
/**
 * WhatsApp Click Post Type
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Post_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WhatsApp_Click
 */
class WhatsApp_Click {

	/**
	 * Register the custom post type.
	 */
	public function register() {
		register_post_type(
			'clicutcl_wa_click',
			array(
				'labels'       => array(
					'name'          => __( 'WhatsApp Clicks', 'click-trail-handler' ),
					'singular_name' => __( 'WhatsApp Click', 'click-trail-handler' ),
				),
				'public'       => false,
				'show_ui'      => false,
				'show_in_menu' => false,
				'capability_type' => 'post',
				'capabilities' => array(
					'create_posts' => 'do_not_allow',
				),
				'map_meta_cap' => true,
				'supports'     => array( 'title' ),
			)
		);
	}
}
