<?php
/**
 * Tracking v2 Settings
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

use CLICUTCL\Core\Storage\Option_Cache;
use CLICUTCL\Support\Feature_Registry;

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
	 * Placeholder returned to admin clients for write-only secret fields.
	 */
	private const SECRET_MASK = '********';

	/**
	 * Prefix used for encrypted secret payloads.
	 */
	private const ENCRYPTED_PREFIX = 'ctenc:v1:';

	/**
	 * Return full settings with defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = Option_Cache::get( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored = self::decode_secret_fields_from_storage( $stored );

		return self::merge_defaults( $stored, self::defaults() );
	}

	/**
	 * Return settings safe for admin clients (masked write-only secrets).
	 *
	 * @return array
	 */
	public static function get_for_admin(): array {
		$settings = self::get();
		return self::mask_secret_fields_for_admin( $settings );
	}

	/**
	 * Return default settings structure.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'feature_flags' => array(
				'event_v2'                     => 1,
				'woocommerce_storefront_events' => 0,
				'external_webhooks'            => 1,
				'connector_native'             => 1,
				'diagnostics_v2'               => 1,
				'lifecycle_ingestion'          => 1,
			),
			'destinations'  => Feature_Registry::destination_defaults(),
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
				'encrypt_secrets_at_rest' => 0,
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
		$current = Option_Cache::get( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$current = self::decode_secret_fields_from_storage( $current );

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
					$current_credentials = $merged['destinations'][ $destination ]['credentials'];
					$current_credentials = is_array( $current_credentials ) ? $current_credentials : array();
					$merged['destinations'][ $destination ]['credentials'] = self::sanitize_credentials_update( $row['credentials'], $current_credentials );
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
					$current_secret = isset( $merged['external_forms']['providers'][ $provider ]['secret'] ) ? (string) $merged['external_forms']['providers'][ $provider ]['secret'] : '';
					$merged['external_forms']['providers'][ $provider ]['secret'] = self::sanitize_secret_update( $row['secret'], $current_secret );
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
				$current_token = isset( $merged['lifecycle']['crm_ingestion']['token'] ) ? (string) $merged['lifecycle']['crm_ingestion']['token'] : '';
				$merged['lifecycle']['crm_ingestion']['token'] = self::sanitize_secret_update( $crm['token'], $current_token );
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
			if ( array_key_exists( 'encrypt_secrets_at_rest', $security ) ) {
				$merged['security']['encrypt_secrets_at_rest'] = ! empty( $security['encrypt_secrets_at_rest'] ) ? 1 : 0;
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

		return self::encode_secret_fields_for_storage( $merged );
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
	 * Check whether browser-side event collection is enabled.
	 *
	 * This is the single capability gate for loading and booting the
	 * browser event collection runtime.
	 *
	 * @return bool
	 */
	public static function browser_event_collection_enabled(): bool {
		return self::feature_enabled( 'event_v2' );
	}

	/**
	 * Check whether WooCommerce storefront events are enabled.
	 *
	 * @return bool
	 */
	public static function woocommerce_storefront_events_enabled(): bool {
		if ( ! self::browser_event_collection_enabled() ) {
			return false;
		}

		return self::feature_enabled( 'woocommerce_storefront_events' );
	}

	/**
	 * Check whether browser events can also use REST delivery transport.
	 *
	 * Collection and transport are intentionally separate concerns:
	 * browser events may still push to dataLayer when collection is enabled
	 * even if server-side delivery is currently off.
	 *
	 * @return bool
	 */
	public static function browser_event_transport_enabled(): bool {
		if ( ! self::browser_event_collection_enabled() ) {
			return false;
		}

		if ( ! class_exists( 'CLICUTCL\\Server_Side\\Dispatcher' ) ) {
			return false;
		}

		return \CLICUTCL\Server_Side\Dispatcher::is_enabled();
	}

	/**
	 * Mask secret fields for admin responses.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	private static function mask_secret_fields_for_admin( array $settings ): array {
		$masked = $settings;

		if ( isset( $masked['external_forms']['providers'] ) && is_array( $masked['external_forms']['providers'] ) ) {
			foreach ( $masked['external_forms']['providers'] as $provider => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				if ( ! empty( $row['secret'] ) ) {
					$masked['external_forms']['providers'][ $provider ]['secret'] = self::SECRET_MASK;
				}
			}
		}

		if ( ! empty( $masked['lifecycle']['crm_ingestion']['token'] ) ) {
			$masked['lifecycle']['crm_ingestion']['token'] = self::SECRET_MASK;
		}

		if ( isset( $masked['destinations'] ) && is_array( $masked['destinations'] ) ) {
			foreach ( $masked['destinations'] as $destination => $row ) {
				if ( ! is_array( $row ) || ! isset( $row['credentials'] ) || ! is_array( $row['credentials'] ) ) {
					continue;
				}

				$masked['destinations'][ $destination ]['credentials'] = self::mask_credentials_map( $row['credentials'] );
			}
		}

		return $masked;
	}

	/**
	 * Mask secret-like fields in credentials map.
	 *
	 * @param array $credentials Credentials.
	 * @return array
	 */
	private static function mask_credentials_map( array $credentials ): array {
		$out = array();
		foreach ( $credentials as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$out[ $key ] = self::mask_credentials_map( $value );
				continue;
			}

			if ( self::looks_like_secret_key( $key ) && '' !== trim( (string) $value ) ) {
				$out[ $key ] = self::SECRET_MASK;
				continue;
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * Sanitize a write-only secret update.
	 *
	 * Empty values and mask placeholders preserve the existing secret.
	 * Use "__clear__" to explicitly clear a secret.
	 *
	 * @param mixed  $value         Submitted value.
	 * @param string $existing      Existing stored secret.
	 * @param int    $max_length    Max length.
	 * @return string
	 */
	private static function sanitize_secret_update( $value, string $existing, int $max_length = 255 ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return $existing;
		}

		$secret = sanitize_text_field( (string) $value );
		if ( '' === $secret || self::is_secret_mask_value( $secret ) ) {
			return $existing;
		}

		if ( '__clear__' === strtolower( $secret ) ) {
			return '';
		}

		return substr( $secret, 0, $max_length );
	}

	/**
	 * Merge credential updates while preserving hidden secret values.
	 *
	 * @param array $incoming Incoming credentials map.
	 * @param array $current  Current credentials map.
	 * @return array
	 */
	private static function sanitize_credentials_update( array $incoming, array $current ): array {
		$out = $current;
		foreach ( $incoming as $raw_key => $item ) {
			$key = sanitize_key( (string) $raw_key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$current_child = isset( $current[ $key ] ) && is_array( $current[ $key ] ) ? $current[ $key ] : array();
				$out[ $key ]   = self::sanitize_credentials_update( $item, $current_child );
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

			$value = sanitize_text_field( (string) $item );
			if ( self::looks_like_secret_key( $key ) ) {
				$existing = isset( $current[ $key ] ) && ( is_scalar( $current[ $key ] ) || null === $current[ $key ] )
					? (string) $current[ $key ]
					: '';
				$out[ $key ] = self::sanitize_secret_update( $value, $existing );
				continue;
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * Check whether a field key likely contains secret material.
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	private static function looks_like_secret_key( string $key ): bool {
		$key = strtolower( trim( $key ) );
		if ( '' === $key ) {
			return false;
		}

		$normalized = str_replace( array( '_', '-' ), '', $key );
		$needles    = array(
			'secret',
			'token',
			'password',
			'apikey',
			'clientsecret',
			'accesstoken',
			'refreshtoken',
			'privatekey',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $normalized, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether value is a masked placeholder from admin UI.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_secret_mask_value( string $value ): bool {
		$value = trim( $value );
		if ( self::SECRET_MASK === $value ) {
			return true;
		}

		return (bool) preg_match( '/^\*{6,}$/', $value );
	}

	/**
	 * Encode secret fields for storage when encryption is enabled.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private static function encode_secret_fields_for_storage( array $settings ): array {
		if ( ! self::should_encrypt_secrets( $settings ) ) {
			return $settings;
		}

		$out = $settings;

		if ( isset( $out['external_forms']['providers'] ) && is_array( $out['external_forms']['providers'] ) ) {
			foreach ( $out['external_forms']['providers'] as $provider => $row ) {
				if ( ! is_array( $row ) || ! isset( $row['secret'] ) ) {
					continue;
				}
				$out['external_forms']['providers'][ $provider ]['secret'] = self::encrypt_secret_value( (string) $row['secret'] );
			}
		}

		if ( isset( $out['lifecycle']['crm_ingestion']['token'] ) ) {
			$out['lifecycle']['crm_ingestion']['token'] = self::encrypt_secret_value( (string) $out['lifecycle']['crm_ingestion']['token'] );
		}

		if ( isset( $out['destinations'] ) && is_array( $out['destinations'] ) ) {
			foreach ( $out['destinations'] as $destination => $row ) {
				if ( ! is_array( $row ) || ! isset( $row['credentials'] ) || ! is_array( $row['credentials'] ) ) {
					continue;
				}

				$out['destinations'][ $destination ]['credentials'] = self::encode_credentials_secret_map( $row['credentials'] );
			}
		}

		return $out;
	}

	/**
	 * Decode secret fields from stored settings.
	 *
	 * @param array $settings Stored settings.
	 * @return array
	 */
	private static function decode_secret_fields_from_storage( array $settings ): array {
		$out = $settings;

		if ( isset( $out['external_forms']['providers'] ) && is_array( $out['external_forms']['providers'] ) ) {
			foreach ( $out['external_forms']['providers'] as $provider => $row ) {
				if ( ! is_array( $row ) || ! isset( $row['secret'] ) ) {
					continue;
				}
				$out['external_forms']['providers'][ $provider ]['secret'] = self::decrypt_secret_value( (string) $row['secret'] );
			}
		}

		if ( isset( $out['lifecycle']['crm_ingestion']['token'] ) ) {
			$out['lifecycle']['crm_ingestion']['token'] = self::decrypt_secret_value( (string) $out['lifecycle']['crm_ingestion']['token'] );
		}

		if ( isset( $out['destinations'] ) && is_array( $out['destinations'] ) ) {
			foreach ( $out['destinations'] as $destination => $row ) {
				if ( ! is_array( $row ) || ! isset( $row['credentials'] ) || ! is_array( $row['credentials'] ) ) {
					continue;
				}

				$out['destinations'][ $destination ]['credentials'] = self::decode_credentials_secret_map( $row['credentials'] );
			}
		}

		return $out;
	}

	/**
	 * Encrypt secret-like values inside credentials map.
	 *
	 * @param array $credentials Credentials.
	 * @return array
	 */
	private static function encode_credentials_secret_map( array $credentials ): array {
		$out = array();
		foreach ( $credentials as $raw_key => $item ) {
			$key = sanitize_key( (string) $raw_key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$out[ $key ] = self::encode_credentials_secret_map( $item );
				continue;
			}

			if ( self::looks_like_secret_key( $key ) ) {
				$out[ $key ] = self::encrypt_secret_value( (string) $item );
				continue;
			}

			$out[ $key ] = $item;
		}

		return $out;
	}

	/**
	 * Decrypt secret-like values inside credentials map.
	 *
	 * @param array $credentials Credentials.
	 * @return array
	 */
	private static function decode_credentials_secret_map( array $credentials ): array {
		$out = array();
		foreach ( $credentials as $raw_key => $item ) {
			$key = sanitize_key( (string) $raw_key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$out[ $key ] = self::decode_credentials_secret_map( $item );
				continue;
			}

			if ( self::looks_like_secret_key( $key ) ) {
				$out[ $key ] = self::decrypt_secret_value( (string) $item );
				continue;
			}

			$out[ $key ] = $item;
		}

		return $out;
	}

	/**
	 * Whether secret-at-rest encryption should be applied.
	 *
	 * @param array $settings Full settings.
	 * @return bool
	 */
	private static function should_encrypt_secrets( array $settings ): bool {
		$enabled = ! empty( $settings['security']['encrypt_secrets_at_rest'] );
		$enabled = (bool) apply_filters( 'clicutcl_encrypt_settings_secrets', $enabled, $settings );
		if ( ! $enabled ) {
			return false;
		}

		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$ciphers = openssl_get_cipher_methods();
		if ( ! is_array( $ciphers ) ) {
			return false;
		}

		return in_array( 'aes-256-gcm', $ciphers, true );
	}

	/**
	 * Encrypt a single secret value.
	 *
	 * @param string $value Plain value.
	 * @return string
	 */
	private static function encrypt_secret_value( string $value ): string {
		if ( '' === $value || self::is_encrypted_value( $value ) ) {
			return $value;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( \Throwable $e ) {
			return $value;
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$value,
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);

		if ( false === $ciphertext || ! is_string( $ciphertext ) || '' === $tag ) {
			return $value;
		}

		$payload = base64_encode( $iv . $tag . $ciphertext );
		if ( ! is_string( $payload ) || '' === $payload ) {
			return $value;
		}

		return self::ENCRYPTED_PREFIX . $payload;
	}

	/**
	 * Decrypt a single secret value.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	private static function decrypt_secret_value( string $value ): string {
		if ( ! self::is_encrypted_value( $value ) ) {
			return $value;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $value;
		}

		$payload = substr( $value, strlen( self::ENCRYPTED_PREFIX ) );
		$raw     = base64_decode( $payload, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return $value;
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$plain = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plain ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging behind WP_DEBUG for decryption troubleshooting.
				error_log( 'ClickTrail: failed to decrypt secret value — key mismatch or corrupted payload.' );
			}
			return '';
		}
		return (string) $plain;
	}

	/**
	 * Check if a value looks like an encrypted payload.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_encrypted_value( string $value ): bool {
		return str_starts_with( $value, self::ENCRYPTED_PREFIX );
	}

	/**
	 * Derive stable encryption key for settings-at-rest.
	 *
	 * @return string
	 */
	private static function encryption_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|' . self::OPTION, true );
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
