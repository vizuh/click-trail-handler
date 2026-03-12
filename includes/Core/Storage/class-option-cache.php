<?php
/**
 * Lightweight option cache helper.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Core\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Option_Cache
 */
class Option_Cache {

	/**
	 * Cache group for plugin options.
	 */
	private const CACHE_GROUP = 'clicutcl_options';

	/**
	 * In-request option cache.
	 *
	 * @var array<string,mixed>
	 */
	private static $request_cache = array();

	/**
	 * Whether invalidation hooks were registered.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Register cache invalidation hooks once per request.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		self::$hooks_registered = true;

		add_action( 'updated_option', array( __CLASS__, 'handle_updated_option' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'handle_added_option' ), 10, 2 );
		add_action( 'deleted_option', array( __CLASS__, 'handle_deleted_option' ), 10, 1 );
	}

	/**
	 * Get an option value with object-cache reuse.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( string $option, $default = false ) {
		self::register_hooks();

		if ( array_key_exists( $option, self::$request_cache ) ) {
			return self::$request_cache[ $option ];
		}

		$found  = false;
		$cached = wp_cache_get( $option, self::CACHE_GROUP, false, $found );
		if ( $found ) {
			self::$request_cache[ $option ] = $cached;
			return $cached;
		}

		$value = get_option( $option, $default );

		self::$request_cache[ $option ] = $value;
		wp_cache_set( $option, $value, self::CACHE_GROUP );

		return $value;
	}

	/**
	 * Warm the cache with a known option value.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return void
	 */
	public static function set( string $option, $value ): void {
		self::$request_cache[ $option ] = $value;
		wp_cache_set( $option, $value, self::CACHE_GROUP );
	}

	/**
	 * Remove a cached option value.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function delete( string $option ): void {
		unset( self::$request_cache[ $option ] );
		wp_cache_delete( $option, self::CACHE_GROUP );
	}

	/**
	 * Sync cache after a successful option update.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public static function handle_updated_option( string $option, $old_value, $value ): void {
		self::set( $option, $value );
	}

	/**
	 * Sync cache after a successful option add.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Added value.
	 * @return void
	 */
	public static function handle_added_option( string $option, $value ): void {
		self::set( $option, $value );
	}

	/**
	 * Clear cache after an option deletion.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function handle_deleted_option( string $option ): void {
		self::delete( $option );
	}
}
