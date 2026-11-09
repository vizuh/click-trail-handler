<?php
/**
 * WordPress function stubs and loader for AdminSettingsSaveTest.
 *
 * Required from the test's setUp() so it only loads inside that test's separate process and never
 * leaks function or class declarations into the rest of the unit suite.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}
	require_once dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';
	\CLICUTCL\Autoloader::run();
	require_once dirname( __DIR__, 2 ) . '/includes/admin/class-admin.php';

	if ( ! function_exists( 'admin_url' ) ) {
		function admin_url( $path = '' ) {
			return 'https://www.example.com/wp-admin/' . $path;
		}
	}
	if ( ! function_exists( 'date_i18n' ) ) {
		function date_i18n( $format, $timestamp = false ) {
			return gmdate( $format, (int) $timestamp );
		}
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'wp_http_validate_url' ) ) {
		function wp_http_validate_url( $url ) {
			return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false;
		}
	}
	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return parse_url( $url, $component );
		}
	}
	if ( ! function_exists( 'wp_parse_args' ) ) {
		function wp_parse_args( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		}
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return (string) $url;
		}
	}
	if ( ! function_exists( 'is_network_admin' ) ) {
		function is_network_admin() {
			return false;
		}
	}
	if ( ! function_exists( 'is_multisite' ) ) {
		function is_multisite() {
			return false;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $option, $value, $autoload = null ) {
			$GLOBALS['clicktrail_test_options'][ $option ]    = $value;
			$GLOBALS['clicktrail_test_update_option_calls'][] = $option;
			return true;
		}
	}
	if ( ! function_exists( 'sanitize_option' ) ) {
		function sanitize_option( $option, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'check_ajax_referer' ) ) {
		function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
			if ( empty( $GLOBALS['clicktrail_test_nonce_ok'] ) ) {
				throw new \RuntimeException( 'nonce_failed' );
			}
			return 1;
		}
	}
	if ( ! function_exists( 'wp_send_json_success' ) ) {
		function wp_send_json_success( $data = null, $status_code = null ) {
			throw new \AdminSettingsSaveResponse( true, $data, $status_code );
		}
	}
	if ( ! function_exists( 'wp_send_json_error' ) ) {
		function wp_send_json_error( $data = null, $status_code = null ) {
			throw new \AdminSettingsSaveResponse( false, $data, $status_code );
		}
	}

	/**
	 * Thrown by the wp_send_json_* stubs in place of core's die().
	 */
	final class AdminSettingsSaveResponse extends \RuntimeException {
		public $ok;
		public $data;
		public $status;

		public function __construct( $ok, $data, $status ) {
			parent::__construct( 'json_response' );
			$this->ok     = $ok;
			$this->data   = $data;
			$this->status = $status;
		}
	}
}
