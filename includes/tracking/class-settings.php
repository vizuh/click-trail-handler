<?php
/**
 * Tracking v2 Settings
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {
	/**
	 * Tracking settings option name.
	 */
	const OPTION = 'clicutcl_tracking_v2';

	/**
	 * Return full settings with defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return self::merge_defaults( $stored, self::defaults() );
	}

	/**
	 * Return default settings structure.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'feature_flags' => array(
				'event_v2'           => 1,
				'external_webhooks'  => 1,
				'connector_native'   => 1,
				'diagnostics_v2'     => 1,
				'lifecycle_ingestion'=> 1,
			),
			'destinations'  => array(
				'meta'      => array( 'enabled' => 0, 'credentials' => array() ),
				'google'    => array( 'enabled' => 0, 'credentials' => array() ),
				'linkedin'  => array( 'enabled' => 0, 'credentials' => array() ),
				'reddit'    => array( 'enabled' => 0, 'credentials' => array() ),
				'pinterest' => array( 'enabled' => 0, 'credentials' => array() ),
			),
			'identity_policy' => array(
				'mode' => 'consent_gated_minimal',
			),
			'external_forms' => array(
				'providers' => array(
					'calendly' => array( 'enabled' => 0, 'secret' => '' ),
					'hubspot'  => array( 'enabled' => 0, 'secret' => '' ),
					'typeform' => array( 'enabled' => 0, 'secret' => '' ),
				),
			),
			'lifecycle' => array(
				'crm_ingestion' => array(
					'enabled' => 0,
					'token'   => '',
				),
			),
			'security' => array(
				'token_ttl_seconds'     => 7 * DAY_IN_SECONDS,
				'token_nonce_limit'     => 0,
				'webhook_replay_window' => 300,
				'rate_limit_window'     => 60,
				'rate_limit_limit'      => 60,
				'trusted_proxies'       => array(),
				'allowed_token_hosts'   => array(),
			),
			'diagnostics' => array(
				'dispatch_buffer_size'    => 20,
				'failure_flush_interval'  => 10,
				'failure_bucket_retention'=> 72,
			),
			'dedup' => array(
				'ttl_seconds' => 7 * DAY_IN_SECONDS,
			),
		);
	}

	/**
	 * Sanitize tracking v2 settings with schema + merge semantics.
	 *
	 * @param mixed $input Submitted option value.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();

		$defaults = self::defaults();
		$merged   = self::merge_defaults( $current, $defaults );
		$input    = is_array( $input ) ? wp_unslash( $input ) : array();

		// Feature flags.
		if ( isset( $input['feature_flags'] ) && is_array( $input['feature_flags'] ) ) {
			foreach ( array_keys( $defaults['feature_flags'] ) as $flag ) {
				if ( array_key_exists( $flag, $input['feature_flags'] ) ) {
					$merged['feature_flags'][ $flag ] = ! empty( $input['feature_flags'][ $flag ] ) ? 1 : 0;
				}
			}
		}

		// Destinations.
		if ( isset( $input['destinations'] ) && is_array( $input['destinations'] ) ) {
			foreach ( array_keys( $defaults['destinations'] ) as $destination ) {
				if ( ! isset( $input['destinations'][ $destination ] ) || ! is_array( $input['destinations'][ $destination ] ) ) {
					continue;
				}

				$row = $input['destinations'][ $destination ];
				if ( array_key_exists( 'enabled', $row ) ) {
					$merged['destinations'][ $destination ]['enabled'] = ! empty( $row['enabled'] ) ? 1 : 0;
				}
				if ( isset( $row['credentials'] ) && is_array( $row['credentials'] ) ) {
					$merged['destinations'][ $destination ]['credentials'] = self::sanitize_scalar_map( $row['credentials'] );
				}
			}
		}

		// Identity policy.
		if ( isset( $input['identity_policy']['mode'] ) ) {
			$mode    = sanitize_key( (string) $input['identity_policy']['mode'] );
			$allowed = array( 'consent_gated_minimal' );
			$merged['identity_policy']['mode'] = in_array( $mode, $allowed, true ) ? $mode : 'consent_gated_minimal';
		}

		// External providers.
		if ( isset( $input['external_forms']['providers'] ) && is_array( $input['external_forms']['providers'] ) ) {
			foreach ( array_keys( $defaults['external_forms']['providers'] ) as $provider ) {
				if ( ! isset( $input['external_forms']['providers'][ $provider ] ) || ! is_array( $input['external_forms']['providers'][ $provider ] ) ) {
					continue;
				}

				$row = $input['external_forms']['providers'][ $provider ];
				if ( array_key_exists( 'enabled', $row ) ) {
					$merged['external_forms']['providers'][ $provider ]['enabled'] = ! empty( $row['enabled'] ) ? 1 : 0;
				}
				if ( array_key_exists( 'secret', $row ) ) {
					$secret = sanitize_text_field( (string) $row['secret'] );
					$merged['external_forms']['providers'][ $provider ]['secret'] = substr( $secret, 0, 255 );
				}
			}
		}

		// Lifecycle ingestion.
		if ( isset( $input['lifecycle']['crm_ingestion'] ) && is_array( $input['lifecycle']['crm_ingestion'] ) ) {
			$crm = $input['lifecycle']['crm_ingestion'];
			if ( array_key_exists( 'enabled', $crm ) ) {
				$merged['lifecycle']['crm_ingestion']['enabled'] = ! empty( $crm['enabled'] ) ? 1 : 0;
			}
			if ( array_key_exists( 'token', $crm ) ) {
				$token = sanitize_text_field( (string) $crm['token'] );
				$merged['lifecycle']['crm_ingestion']['token'] = substr( $token, 0, 255 );
			}
		}

		// Security.
		if ( isset( $input['security'] ) && is_array( $input['security'] ) ) {
			$security = $input['security'];
			if ( array_key_exists( 'token_ttl_seconds', $security ) ) {
				$ttl = absint( $security['token_ttl_seconds'] );
				$merged['security']['token_ttl_seconds'] = max( 60, min( 7 * DAY_IN_SECONDS, $ttl ) );
			}
			if ( array_key_exists( 'token_nonce_limit', $security ) ) {
				$limit = absint( $security['token_nonce_limit'] );
				$merged['security']['token_nonce_limit'] = max( 0, min( 5000, $limit ) );
			}
			if ( array_key_exists( 'webhook_replay_window', $security ) ) {
				$window = absint( $security['webhook_replay_window'] );
				$merged['security']['webhook_replay_window'] = max( 60, min( 3600, $window ) );
			}
			if ( array_key_exists( 'rate_limit_window', $security ) ) {
				$window = absint( $security['rate_limit_window'] );
				$merged['security']['rate_limit_window'] = max( 5, min( 3600, $window ) );
			}
			if ( array_key_exists( 'rate_limit_limit', $security ) ) {
				$limit = absint( $security['rate_limit_limit'] );
				$merged['security']['rate_limit_limit'] = max( 1, min( 2000, $limit ) );
			}
			if ( array_key_exists( 'trusted_proxies', $security ) ) {
				$merged['security']['trusted_proxies'] = self::sanitize_proxies_list( $security['trusted_proxies'] );
			}
			if ( array_key_exists( 'allowed_token_hosts', $security ) ) {
				$merged['security']['allowed_token_hosts'] = self::sanitize_hosts_list( $security['allowed_token_hosts'] );
			}
		}

		// Diagnostics.
		if ( isset( $input['diagnostics'] ) && is_array( $input['diagnostics'] ) ) {
			$diag = $input['diagnostics'];
			if ( array_key_exists( 'dispatch_buffer_size', $diag ) ) {
				$size = absint( $diag['dispatch_buffer_size'] );
				$merged['diagnostics']['dispatch_buffer_size'] = max( 1, min( 200, $size ) );
			}
			if ( array_key_exists( 'failure_flush_interval', $diag ) ) {
				$interval = absint( $diag['failure_flush_interval'] );
				$merged['diagnostics']['failure_flush_interval'] = min( 300, $interval );
			}
			if ( array_key_exists( 'failure_bucket_retention', $diag ) ) {
				$retention = absint( $diag['failure_bucket_retention'] );
				$merged['diagnostics']['failure_bucket_retention'] = max( 1, min( 720, $retention ) );
			}
		}

		// Dedup.
		if ( isset( $input['dedup'] ) && is_array( $input['dedup'] ) && array_key_exists( 'ttl_seconds', $input['dedup'] ) ) {
			$ttl = absint( $input['dedup']['ttl_seconds'] );
			$merged['dedup']['ttl_seconds'] = max( DAY_IN_SECONDS, min( 30 * DAY_IN_SECONDS, $ttl ) );
		}

		return $merged;
	}

	/**
	 * Resolve provider secret.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	public static function get_provider_secret( string $provider ): string {
		$provider = sanitize_key( $provider );
		$settings = self::get();

		$secret = '';
		if ( ! empty( $settings['external_forms']['providers'][ $provider ]['secret'] ) ) {
			$secret = sanitize_text_field( (string) $settings['external_forms']['providers'][ $provider ]['secret'] );
		}

		/**
		 * Filter provider webhook secret.
		 *
		 * @param string $secret   Secret.
		 * @param string $provider Provider key.
		 */
		$secret = apply_filters( 'clicutcl_external_provider_secret', $secret, $provider );
		return sanitize_text_field( (string) $secret );
	}

	/**
	 * Check if provider integration is enabled.
	 *
	 * @param string $provider Provider key.
	 * @return bool
	 */
	public static function is_provider_enabled( string $provider ): bool {
		$provider = sanitize_key( $provider );
		$settings = self::get();
		$enabled  = ! empty( $settings['external_forms']['providers'][ $provider ]['enabled'] );

		/**
		 * Filter provider enabled state.
		 *
		 * @param bool   $enabled  Whether provider is enabled.
		 * @param string $provider Provider key.
		 */
		return (bool) apply_filters( 'clicutcl_external_provider_enabled', $enabled, $provider );
	}

	/**
	 * Return CRM lifecycle token.
	 *
	 * @return string
	 */
	public static function get_lifecycle_token(): string {
		$settings = self::get();
		$token    = $settings['lifecycle']['crm_ingestion']['token'] ?? '';
		$token    = apply_filters( 'clicutcl_lifecycle_token', $token );
		return sanitize_text_field( (string) $token );
	}

	/**
	 * Check whether feature flag is enabled.
	 *
	 * @param string $flag Flag key.
	 * @return bool
	 */
	public static function feature_enabled( string $flag ): bool {
		$flag     = sanitize_key( $flag );
		$settings = self::get();

		return ! empty( $settings['feature_flags'][ $flag ] );
	}

	/**
	 * Recursive defaults merge (stored values win).
	 *
	 * @param array $stored   Stored value.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	private static function merge_defaults( array $stored, array $defaults ): array {
		foreach ( $defaults as $key => $default_value ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				$stored[ $key ] = $default_value;
				continue;
			}

			if ( is_array( $default_value ) && is_array( $stored[ $key ] ) ) {
				$stored[ $key ] = self::merge_defaults( $stored[ $key ], $default_value );
			}
		}

		return $stored;
	}

	/**
	 * Sanitize key/value scalar map recursively.
	 *
	 * @param array $value Raw value.
	 * @return array
	 */
	private static function sanitize_scalar_map( array $value ): array {
		$out = array();
		foreach ( $value as $key => $item ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$out[ $key ] = self::sanitize_scalar_map( $item );
				continue;
			}

			if ( is_bool( $item ) ) {
				$out[ $key ] = (bool) $item;
				continue;
			}

			if ( is_numeric( $item ) ) {
				$out[ $key ] = $item + 0;
				continue;
			}

			$out[ $key ] = sanitize_text_field( (string) $item );
		}

		return $out;
	}

	/**
	 * Sanitize trusted proxy list (array or CSV/newline string).
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	private static function sanitize_proxies_list( $input ): array {
		$items = $input;
		if ( is_string( $items ) ) {
			$items = preg_split( '/[\r\n,\s]+/', $items );
		}
		if ( ! is_array( $items ) ) {
			return array();
		}

		$out = array();
		foreach ( $items as $entry ) {
			$entry = trim( sanitize_text_field( (string) $entry ) );
			if ( '' === $entry ) {
				continue;
			}

			if ( filter_var( $entry, FILTER_VALIDATE_IP ) ) {
				$out[] = $entry;
				continue;
			}

			if ( preg_match( '/^[0-9a-f:.]+\/\d{1,3}$/i', $entry ) ) {
				$out[] = $entry;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize allowed host list (array or CSV/newline string).
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	private static function sanitize_hosts_list( $input ): array {
		$items = $input;
		if ( is_string( $items ) ) {
			$items = preg_split( '/[\r\n,\s]+/', $items );
		}
		if ( ! is_array( $items ) ) {
			return array();
		}

		$out = array();
		foreach ( $items as $host ) {
			$host = strtolower( trim( sanitize_text_field( (string) $host ) ) );
			if ( '' === $host ) {
				continue;
			}

			// Hostname only, no scheme/path.
			$host = preg_replace( '#^https?://#i', '', $host );
			$host = preg_replace( '#/.*$#', '', $host );
			if ( preg_match( '/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $host ) ) {
				$out[] = $host;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
