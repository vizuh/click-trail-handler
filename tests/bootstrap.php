<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the project autoloader and stubs the small set of WordPress functions
 * used by classes under test, so unit tests can run without a full WP install.
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Pass-through stub for tests; first arg is returned unchanged.
	 *
	 * @param string $tag   Filter tag (unused).
	 * @param mixed  $value Value to return.
	 * @return mixed
	 */
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Trim and strip control chars; mirrors core's basic shape.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		$s = (string) $value;
		$s = preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
		return trim( $s );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;

		public function __construct( string $code ) {
			$this->code = $code;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $headers;
		private string $body;
		private string $route;

		public function __construct( array $headers, string $body, string $route = '/clicutcl/v2/webhooks/test' ) {
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
			$this->body    = $body;
			$this->route   = $route;
		}

		public function get_header( string $name ): string {
			return (string) ( $this->headers[ strtolower( $name ) ] ?? '' );
		}

		public function get_body(): string {
			return $this->body;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache() {
		return false;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['clicktrail_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value ) {
		$GLOBALS['clicktrail_test_transients'][ $key ] = $value;
		return true;
	}
}

// Each test file is responsible for `require_once`-ing its target classes.
