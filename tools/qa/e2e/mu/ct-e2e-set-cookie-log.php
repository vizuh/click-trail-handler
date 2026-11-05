<?php
/**
 * E2E only: record what the server sends back for `POST /clicutcl/v2/cookie-refresh` (#141).
 *
 * Chromium reports no response for a `keepalive` fetch (Playwright `response` events and CDP network events never fire
 * for it), so the wire is observed at the end of the request on the server: status, the `Set-Cookie` and `Cache-Control`
 * headers PHP sends, and whether the request carried an `attribution` cookie. Read-only; never deploy.
 *
 * @package ClickTrail
 */

add_action(
	'shutdown',
	static function () {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Throwaway E2E observer.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( false === strpos( $uri, 'clicutcl/v2/cookie-refresh' ) ) {
			return;
		}
		$headers = array();
		foreach ( headers_list() as $line ) {
			if ( 0 === stripos( $line, 'Set-Cookie:' ) || 0 === stripos( $line, 'Cache-Control:' ) ) {
				$headers[] = $line;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Throwaway E2E capture file.
		file_put_contents(
			WP_CONTENT_DIR . '/ct-set-cookie.jsonl',
			wp_json_encode(
				array(
					'method'        => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
					'status'        => http_response_code(),
					'request_cookie' => isset( $_COOKIE['attribution'] ),
					'headers'       => $headers,
				)
			) . "\n",
			FILE_APPEND
		);
	},
	PHP_INT_MAX
);
