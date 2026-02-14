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
				'token_ttl_seconds'     => 43200,
				'token_nonce_limit'     => 0,
				'webhook_replay_window' => 300,
				'trusted_proxies'       => array(),
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
}
