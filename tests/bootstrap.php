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

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
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

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation passthrough stub for tests.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain (unused).
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment, Universal.NamingConventions.UnderscoreName -- Stubbing WP core i18n function.
		return $text;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Capability check stub; tests flip this via the global override.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	function current_user_can( $capability ) {
		return (bool) ( $GLOBALS['clicktrail_test_current_user_can'] ?? false );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Nonce verification stub; tests flip this via the global override.
	 *
	 * @param string $nonce  Nonce.
	 * @param string $action Action.
	 * @return bool
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return (bool) ( $GLOBALS['clicktrail_test_wp_verify_nonce'] ?? false );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Mirrors WordPress core get_option() signature.
		return $default;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$found = false;
		return false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		return true;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return (int) ( $GLOBALS['clicktrail_test_blog_id'] ?? 1 );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		$base = (string) ( $GLOBALS['clicktrail_test_home_url'] ?? 'https://example.test' );
		return $base . $path;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Stubbing WP core wrapper for tests.
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stubbing WP core wrapper for tests.
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
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

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	class WP_REST_Controller {
		protected $namespace;
		protected $rest_base;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements \ArrayAccess {
		private array $headers;
		private string $body;
		private string $route;
		private array $params;

		public function __construct( array $headers, string $body, string $route = '/clicutcl/v2/webhooks/test', array $params = array() ) {
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
			$this->body    = $body;
			$this->route   = $route;
			$this->params  = $params;
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

		public function get_json_params() {
			$decoded = json_decode( $this->body, true );
			return is_array( $decoded ) ? $decoded : null;
		}

		public function get_param( string $key ) {
			if ( array_key_exists( $key, $this->params ) ) {
				return $this->params[ $key ];
			}
			$body = $this->get_json_params();
			return is_array( $body ) && array_key_exists( $key, $body ) ? $body[ $key ] : '';
		}

		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		public function offsetGet( $offset ): mixed {
			return $this->params[ $offset ] ?? '';
		}

		public function offsetSet( $offset, $value ): void {
			if ( null === $offset ) {
				$this->params[] = $value;
			} else {
				$this->params[ $offset ] = $value;
			}
		}

		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
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
