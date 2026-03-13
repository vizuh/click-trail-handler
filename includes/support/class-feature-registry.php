<?php
/**
 * Feature registry loader.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared capability and adapter registry.
 */
class Feature_Registry {
	/**
	 * Cached registry payload.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $registry = null;

	/**
	 * Return the parsed registry payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		$path = defined( 'CLICUTCL_DIR' )
			? CLICUTCL_DIR . 'config/feature-registry.json'
			: dirname( __DIR__, 2 ) . '/config/feature-registry.json';
		$raw  = is_readable( $path ) ? file_get_contents( $path ) : false;
		$data = is_string( $raw ) ? json_decode( $raw, true ) : array();
		$data = is_array( $data ) ? $data : array();

		self::$registry = array(
			'schema_version'   => absint( $data['schema_version'] ?? 0 ),
			'delivery_adapters'=> isset( $data['delivery_adapters'] ) && is_array( $data['delivery_adapters'] ) ? $data['delivery_adapters'] : array(),
			'destinations'     => isset( $data['destinations'] ) && is_array( $data['destinations'] ) ? $data['destinations'] : array(),
			'features'         => isset( $data['features'] ) && is_array( $data['features'] ) ? $data['features'] : array(),
		);

		return self::$registry;
	}

	/**
	 * Return delivery adapter registry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function delivery_adapters(): array {
		$registry = self::all();
		return isset( $registry['delivery_adapters'] ) && is_array( $registry['delivery_adapters'] )
			? $registry['delivery_adapters']
			: array();
	}

	/**
	 * Return destination registry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function destinations(): array {
		$registry = self::all();
		return isset( $registry['destinations'] ) && is_array( $registry['destinations'] )
			? $registry['destinations']
			: array();
	}

	/**
	 * Return feature registry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function features(): array {
		$registry = self::all();
		return isset( $registry['features'] ) && is_array( $registry['features'] )
			? $registry['features']
			: array();
	}

	/**
	 * Return adapter choices for admin controls.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function delivery_adapter_choices(): array {
		$choices = array();
		foreach ( self::delivery_adapters() as $key => $adapter ) {
			$choices[] = array(
				'value' => sanitize_key( (string) $key ),
				'label' => sanitize_text_field( (string) ( $adapter['label'] ?? $key ) ),
			);
		}

		return $choices;
	}

	/**
	 * Return adapter label.
	 *
	 * @param string $key Adapter key.
	 * @return string
	 */
	public static function adapter_label( string $key ): string {
		$key      = sanitize_key( $key );
		$registry = self::delivery_adapters();
		return isset( $registry[ $key ]['label'] ) ? sanitize_text_field( (string) $registry[ $key ]['label'] ) : $key;
	}

	/**
	 * Return adapter class name.
	 *
	 * @param string $key Adapter key.
	 * @return string
	 */
	public static function adapter_class( string $key ): string {
		$key      = sanitize_key( $key );
		$registry = self::delivery_adapters();
		return isset( $registry[ $key ]['class'] ) ? ltrim( (string) $registry[ $key ]['class'], '\\' ) : '';
	}

	/**
	 * Return adapter key allowlist.
	 *
	 * @return array<string,true>
	 */
	public static function allowed_adapter_keys(): array {
		$allowed = array();
		foreach ( array_keys( self::delivery_adapters() ) as $key ) {
			$allowed[ sanitize_key( (string) $key ) ] = true;
		}

		return $allowed;
	}

	/**
	 * Return destination defaults for tracking settings.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function destination_defaults(): array {
		$defaults = array();
		foreach ( self::destinations() as $key => $row ) {
			$defaults[ sanitize_key( (string) $key ) ] = array(
				'enabled'     => 0,
				'credentials' => array(),
			);
		}

		return $defaults;
	}

	/**
	 * Return destination toggle list for admin clients.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function destination_toggle_list(): array {
		$list = array();
		foreach ( self::destinations() as $key => $destination ) {
			$list[] = array(
				'key'           => sanitize_key( (string) $key ),
				'label'         => sanitize_text_field( (string) ( $destination['label'] ?? $key ) ),
				'support_level' => sanitize_key( (string) ( $destination['support_level'] ?? 'unknown' ) ),
			);
		}

		return $list;
	}

	/**
	 * Return destination label.
	 *
	 * @param string $key Destination key.
	 * @return string
	 */
	public static function destination_label( string $key ): string {
		$key      = sanitize_key( $key );
		$registry = self::destinations();
		return isset( $registry[ $key ]['label'] ) ? sanitize_text_field( (string) $registry[ $key ]['label'] ) : $key;
	}

	/**
	 * Return destination metadata.
	 *
	 * @param string $key Destination key.
	 * @return array<string,mixed>
	 */
	public static function destination( string $key ): array {
		$key      = sanitize_key( $key );
		$registry = self::destinations();
		return isset( $registry[ $key ] ) && is_array( $registry[ $key ] ) ? $registry[ $key ] : array();
	}
}
