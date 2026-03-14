<?php
/**
 * Class ClickTrail\Modules\GTM\GTM_Settings
 *
 * @package   ClickTrail
 */

namespace CLICUTCL\Modules\GTM;

use CLICUTCL\Core\Storage\Setting;

/**
 * Class to store GTM settings.
 */
class GTM_Settings extends Setting {

	/**
	 * The user option name for this setting.
	 */
	const OPTION = 'clicutcl_gtm';

	/**
	 * Gets the expected value type.
	 *
	 * @return string The type name.
	 */
	protected function get_type() {
		return 'object';
	}

	/**
	 * Gets the default value.
	 *
	 * @return array The default value.
	 */
	protected function get_default() {
		return self::defaults();
	}

	/**
	 * Gets the callback for sanitizing the setting's value before saving.
	 *
	 * @return callable Sanitize callback.
	 */
	protected function get_sanitize_callback() {
		return function ( $value ) {
			return self::sanitize( $value, $this->get() );
		};
	}

	/**
	 * Return default GTM settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'container_id'            => '',
			'mode'                    => 'standard',
			'tagging_server_url'      => '',
			'first_party_script'      => 0,
			'custom_loader_enabled'   => 0,
			'custom_loader_url'       => '',
			'woo_enhanced_datalayer'  => 0,
			'woo_include_user_data'   => 0,
		);
	}

	/**
	 * Sanitize GTM settings payload.
	 *
	 * @param mixed $value   Submitted value.
	 * @param array $current Current settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $value, array $current = array() ): array {
		$new_value = wp_parse_args( is_array( $current ) ? $current : array(), self::defaults() );
		if ( ! is_array( $value ) ) {
			return $new_value;
		}

		if ( array_key_exists( 'container_id', $value ) ) {
			$container_id = sanitize_text_field( (string) $value['container_id'] );

			if ( ! empty( $container_id ) && ! preg_match( '/^GTM-[A-Z0-9]+$/i', $container_id ) ) {
				add_settings_error(
					'clicutcl_gtm',
					'invalid_container_id',
					__( 'Invalid GTM Container ID format. Should be GTM-XXXXXX', 'click-trail-handler' )
				);
				$container_id = '';
			}

			$new_value['container_id'] = $container_id;
		}

		if ( array_key_exists( 'mode', $value ) ) {
			$mode              = sanitize_key( (string) $value['mode'] );
			$new_value['mode'] = in_array( $mode, array( 'standard', 'sgtm' ), true ) ? $mode : 'standard';
		}

		if ( array_key_exists( 'tagging_server_url', $value ) ) {
			$new_value['tagging_server_url'] = self::sanitize_absolute_url( (string) $value['tagging_server_url'], true );
		}

		if ( array_key_exists( 'first_party_script', $value ) ) {
			$new_value['first_party_script'] = ! empty( $value['first_party_script'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'custom_loader_enabled', $value ) ) {
			$new_value['custom_loader_enabled'] = ! empty( $value['custom_loader_enabled'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'custom_loader_url', $value ) ) {
			$new_value['custom_loader_url'] = self::sanitize_loader_url( (string) $value['custom_loader_url'] );
		}

		if ( array_key_exists( 'woo_enhanced_datalayer', $value ) ) {
			$new_value['woo_enhanced_datalayer'] = ! empty( $value['woo_enhanced_datalayer'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'woo_include_user_data', $value ) ) {
			$new_value['woo_include_user_data'] = ! empty( $value['woo_include_user_data'] ) ? 1 : 0;
		}

		if ( empty( $new_value['custom_loader_url'] ) ) {
			$new_value['custom_loader_enabled'] = 0;
		}

		return wp_parse_args( $new_value, self::defaults() );
	}

	/**
	 * Accessor for the `container_id` setting.
	 *
	 * @return string GTM Container ID.
	 */
	public function get_container_id() {
		$settings = $this->get();
		return isset( $settings['container_id'] ) ? $settings['container_id'] : '';
	}

	/**
	 * Accessor for the GTM delivery mode.
	 *
	 * @return string
	 */
	public function get_mode(): string {
		$settings = $this->get();
		$mode     = isset( $settings['mode'] ) ? sanitize_key( (string) $settings['mode'] ) : 'standard';
		return in_array( $mode, array( 'standard', 'sgtm' ), true ) ? $mode : 'standard';
	}

	/**
	 * Accessor for the tagging server URL.
	 *
	 * @return string
	 */
	public function get_tagging_server_url(): string {
		$settings = $this->get();
		return isset( $settings['tagging_server_url'] ) ? (string) $settings['tagging_server_url'] : '';
	}

	/**
	 * Whether first-party GTM script delivery is enabled.
	 *
	 * @return bool
	 */
	public function use_first_party_script(): bool {
		$settings = $this->get();
		return ! empty( $settings['first_party_script'] );
	}

	/**
	 * Whether a custom loader URL is enabled.
	 *
	 * @return bool
	 */
	public function use_custom_loader(): bool {
		$settings = $this->get();
		return ! empty( $settings['custom_loader_enabled'] ) && ! empty( $settings['custom_loader_url'] );
	}

	/**
	 * Accessor for the custom loader URL or same-site path.
	 *
	 * @return string
	 */
	public function get_custom_loader_url(): string {
		$settings = $this->get();
		return isset( $settings['custom_loader_url'] ) ? (string) $settings['custom_loader_url'] : '';
	}

	/**
	 * Whether the enhanced Woo dataLayer contract is enabled.
	 *
	 * @return bool
	 */
	public function woo_enhanced_datalayer_enabled(): bool {
		$settings = $this->get();
		return ! empty( $settings['woo_enhanced_datalayer'] );
	}

	/**
	 * Whether Woo dataLayer payloads may include consent-aware user_data.
	 *
	 * @return bool
	 */
	public function woo_include_user_data(): bool {
		$settings = $this->get();
		return ! empty( $settings['woo_include_user_data'] );
	}

	/**
	 * Build the GTM loader script URL for the current mode.
	 *
	 * @param array  $settings      GTM settings payload.
	 * @param string $container_id  GTM container ID.
	 * @param string $data_layer    Data layer name.
	 * @return string
	 */
	public static function build_script_src( array $settings, string $container_id, string $data_layer = 'dataLayer' ): string {
		$container_id = sanitize_text_field( $container_id );
		if ( '' === $container_id ) {
			return '';
		}

		$mode              = isset( $settings['mode'] ) ? sanitize_key( (string) $settings['mode'] ) : 'standard';
		$custom_loader_url = isset( $settings['custom_loader_url'] ) ? (string) $settings['custom_loader_url'] : '';
		if ( 'sgtm' === $mode && ! empty( $settings['custom_loader_enabled'] ) && '' !== $custom_loader_url ) {
			return self::append_container_to_loader_url( $custom_loader_url, $container_id, $data_layer );
		}

		if ( 'sgtm' === $mode && ! empty( $settings['first_party_script'] ) ) {
			$base = isset( $settings['tagging_server_url'] ) ? self::sanitize_absolute_url( (string) $settings['tagging_server_url'], true ) : '';
			if ( '' !== $base ) {
				return self::append_query_args_to_url( $base . '/gtm.js', array( 'id' => $container_id ) );
			}
		}

		return 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode( $container_id ) . ( 'dataLayer' !== $data_layer ? '&l=' . rawurlencode( $data_layer ) : '' );
	}

	/**
	 * Build the GTM noscript iframe URL for the current mode.
	 *
	 * @param array  $settings     GTM settings payload.
	 * @param string $container_id GTM container ID.
	 * @return string
	 */
	public static function build_noscript_src( array $settings, string $container_id ): string {
		$container_id = sanitize_text_field( $container_id );
		if ( '' === $container_id ) {
			return '';
		}

		$mode = isset( $settings['mode'] ) ? sanitize_key( (string) $settings['mode'] ) : 'standard';
		if ( 'sgtm' === $mode && ! empty( $settings['first_party_script'] ) ) {
			$base = isset( $settings['tagging_server_url'] ) ? self::sanitize_absolute_url( (string) $settings['tagging_server_url'], true ) : '';
			if ( '' !== $base ) {
				return self::append_query_args_to_url( $base . '/ns.html', array( 'id' => $container_id ) );
			}
		}

		return 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $container_id );
	}

	/**
	 * Sanitize an absolute HTTP(S) URL.
	 *
	 * @param string $value       Raw URL.
	 * @param bool   $trim_slash  Whether to remove the trailing slash.
	 * @return string
	 */
	public static function sanitize_absolute_url( string $value, bool $trim_slash = false ): string {
		$url = esc_url_raw( trim( $value ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}

		return $trim_slash ? untrailingslashit( $url ) : $url;
	}

	/**
	 * Sanitize a custom loader URL or same-site path.
	 *
	 * @param string $value Raw loader value.
	 * @return string
	 */
	private static function sanitize_loader_url( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}

		if ( '/' === substr( $value, 0, 1 ) ) {
			return $value;
		}

		return self::sanitize_absolute_url( $value );
	}

	/**
	 * Append a container ID to a loader URL.
	 *
	 * @param string $loader_url   Loader URL.
	 * @param string $container_id Container ID.
	 * @param string $data_layer   Data layer name.
	 * @return string
	 */
	private static function append_container_to_loader_url( string $loader_url, string $container_id, string $data_layer = 'dataLayer' ): string {
		if ( false !== strpos( $loader_url, '%s' ) ) {
			return sprintf( $loader_url, rawurlencode( $container_id ) );
		}

		if ( false !== strpos( $loader_url, '{container_id}' ) ) {
			return str_replace( '{container_id}', rawurlencode( $container_id ), $loader_url );
		}

		$args = array( 'id' => $container_id );
		if ( 'dataLayer' !== $data_layer ) {
			$args['l'] = $data_layer;
		}

		return self::append_query_args_to_url( $loader_url, $args );
	}

	/**
	 * Append query arguments to an absolute URL or same-site path.
	 *
	 * @param string $url  Base URL.
	 * @param array  $args Query arguments.
	 * @return string
	 */
	private static function append_query_args_to_url( string $url, array $args ): string {
		if ( '' === $url ) {
			return '';
		}

		if ( '/' === substr( $url, 0, 1 ) ) {
			$fragment = '';
			if ( false !== strpos( $url, '#' ) ) {
				list( $url, $fragment ) = explode( '#', $url, 2 );
				$fragment = '#' . $fragment;
			}

			$separator = false !== strpos( $url, '?' ) ? '&' : '?';
			return $url . $separator . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) . $fragment;
		}

		return add_query_arg( $args, $url );
	}
}
