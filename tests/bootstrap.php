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

// Each test file is responsible for `require_once`-ing its target classes.
