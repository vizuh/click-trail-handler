<?php
/**
 * E2E only: capture ClickTrail server-side dispatches and short-circuit mail. Never deploy.
 *
 * @package ClickTrail
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'capture.invalid' ) ) {
			return $pre;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Throwaway E2E capture file.
		file_put_contents( WP_CONTENT_DIR . '/ct-capture.jsonl', (string) $args['body'] . "\n", FILE_APPEND );
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	},
	10,
	3
);
add_filter( 'pre_wp_mail', '__return_true' );
