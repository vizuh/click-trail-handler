<?php
/**
 * Attribution-token internals for Tracking_Controller.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Api;

use CLICUTCL\Settings\Attribution_Settings;
use CLICUTCL\Tracking\Settings as Tracking_Settings;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Tracking_Controller_Attribution_Token_Trait {

	/**
	 * Extract bearer token from header/body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function extract_token_from_request( WP_REST_Request $request ): string {
		$token = sanitize_text_field( (string) $request->get_header( 'x-clicutcl-token' ) );
		if ( '' !== $token ) {
			return $token;
		}

		$payload = $request->get_json_params();
		if ( is_array( $payload ) && ! empty( $payload['token'] ) ) {
			return sanitize_text_field( (string) $payload['token'] );
		}

		return '';
	}

	/**
	 * Sanitize attribution payload used in signed ct_token data.
	 *
	 * @param array $data Raw payload.
	 * @return array
	 */
	private function sanitize_attribution_token_data( array $data ): array {
		$allowed_keys = array(
			'ft_source',
			'ft_medium',
			'ft_campaign',
			'ft_term',
			'ft_content',
			'ft_utm_id',
			'ft_utm_source_platform',
			'ft_utm_creative_format',
			'ft_utm_marketing_tactic',
			'lt_source',
			'lt_medium',
			'lt_campaign',
			'lt_term',
			'lt_content',
			'lt_utm_id',
			'lt_utm_source_platform',
			'lt_utm_creative_format',
			'lt_utm_marketing_tactic',
			'gclid',
			'fbclid',
			'msclkid',
			'ttclid',
			'wbraid',
			'gbraid',
			'twclid',
			'li_fat_id',
			'sccid',
			'sc_click_id',
			'ScCid',
			'epik',
			'ft_gclid',
			'ft_fbclid',
			'ft_msclkid',
			'ft_ttclid',
			'ft_wbraid',
			'ft_gbraid',
			'ft_twclid',
			'ft_li_fat_id',
			'ft_sccid',
			'ft_sc_click_id',
			'ft_ScCid',
			'ft_epik',
			'lt_gclid',
			'lt_fbclid',
			'lt_msclkid',
			'lt_ttclid',
			'lt_wbraid',
			'lt_gbraid',
			'lt_twclid',
			'lt_li_fat_id',
			'lt_sccid',
			'lt_sc_click_id',
			'lt_ScCid',
			'lt_epik',
		);

		$out = array();
		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}

			$value = $data[ $key ];
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			if ( strlen( $value ) > 128 ) {
				$value = substr( $value, 0, 128 );
			}

			$out[ $key ] = $value;
		}

		$out = $this->normalize_click_ids_for_token( $out );
		if ( ! empty( $out['sccid'] ) ) {
			unset( $out['sc_click_id'] );
			unset( $out['ft_sc_click_id'] );
			unset( $out['lt_sc_click_id'] );
			unset( $out['ScCid'] );
			unset( $out['ft_ScCid'] );
			unset( $out['lt_ScCid'] );
		}

		return $out;
	}

	/**
	 * Normalize click IDs (including legacy Snapchat aliases).
	 *
	 * @param array $attribution Attribution.
	 * @return array
	 */
	private function normalize_click_ids_for_token( array $attribution ): array {
		$keys = array( 'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid', 'twclid', 'li_fat_id', 'sccid', 'epik' );
		foreach ( $keys as $key ) {
			if ( empty( $attribution[ $key ] ) ) {
				if ( ! empty( $attribution[ 'lt_' . $key ] ) ) {
					$attribution[ $key ] = $attribution[ 'lt_' . $key ];
				} elseif ( ! empty( $attribution[ 'ft_' . $key ] ) ) {
					$attribution[ $key ] = $attribution[ 'ft_' . $key ];
				}
			}
		}

		if ( empty( $attribution['sccid'] ) ) {
			if ( ! empty( $attribution['sc_click_id'] ) ) {
				$attribution['sccid'] = $attribution['sc_click_id'];
			} elseif ( ! empty( $attribution['ScCid'] ) ) {
				$attribution['sccid'] = $attribution['ScCid'];
			} elseif ( ! empty( $attribution['lt_sc_click_id'] ) ) {
				$attribution['sccid'] = $attribution['lt_sc_click_id'];
			} elseif ( ! empty( $attribution['lt_ScCid'] ) ) {
				$attribution['sccid'] = $attribution['lt_ScCid'];
			} elseif ( ! empty( $attribution['ft_sc_click_id'] ) ) {
				$attribution['sccid'] = $attribution['ft_sc_click_id'];
			} elseif ( ! empty( $attribution['ft_ScCid'] ) ) {
				$attribution['sccid'] = $attribution['ft_ScCid'];
			}
		}

		return $attribution;
	}

	/**
	 * Mint signed token for attribution payload.
	 *
	 * @param array $data Attribution payload.
	 * @return string
	 */
	private function mint_signed_attribution_token( array $data ): string {
		$now    = time();
		$claims = array(
			'v'     => 1,
			'type'  => 'attribution',
			'iat'   => $now,
			'exp'   => $now + $this->get_attribution_token_ttl(),
			'host'  => $this->current_token_host(),
			'blog'  => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'nonce' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ct_', true ),
			'data'  => $data,
		);

		$json = wp_json_encode( $claims );
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		$payload = $this->base64url_encode( $json );
		$sig     = $this->base64url_encode( hash_hmac( 'sha256', $payload, $this->attribution_token_signing_key(), true ) );

		return $payload . '.' . $sig;
	}

	/**
	 * Verify signed attribution token.
	 *
	 * @param string $token Token.
	 * @return array|WP_Error
	 */
	private function verify_signed_attribution_token( string $token ) {
		$token = trim( $token );
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return new WP_Error( 'invalid_token', 'Invalid attribution token', array( 'status' => 401 ) );
		}

		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'invalid_token', 'Invalid attribution token', array( 'status' => 401 ) );
		}

		list( $payload_b64, $sig_b64 ) = $parts;
		$expected                      = $this->base64url_encode( hash_hmac( 'sha256', $payload_b64, $this->attribution_token_signing_key(), true ) );
		if ( ! hash_equals( $expected, $sig_b64 ) ) {
			return new WP_Error( 'invalid_signature', 'Invalid attribution token signature', array( 'status' => 401 ) );
		}

		$payload = $this->base64url_decode( $payload_b64 );
		$claims  = json_decode( $payload, true );
		if ( ! is_array( $claims ) ) {
			return new WP_Error( 'invalid_token', 'Invalid attribution token payload', array( 'status' => 401 ) );
		}

		if ( empty( $claims['exp'] ) || (int) $claims['exp'] < time() ) {
			return new WP_Error( 'token_expired', 'Attribution token expired', array( 'status' => 401 ) );
		}
		if ( empty( $claims['type'] ) || 'attribution' !== sanitize_key( (string) $claims['type'] ) ) {
			return new WP_Error( 'invalid_token_type', 'Invalid attribution token type', array( 'status' => 401 ) );
		}

		$token_host = isset( $claims['host'] ) ? sanitize_text_field( (string) $claims['host'] ) : '';
		if ( ! $this->is_allowed_token_host( $token_host ) ) {
			return new WP_Error( 'token_site_mismatch', 'Attribution token site mismatch', array( 'status' => 401 ) );
		}

		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( isset( $claims['blog'] ) && (int) $claims['blog'] !== $blog ) {
			return new WP_Error( 'token_blog_mismatch', 'Attribution token blog mismatch', array( 'status' => 401 ) );
		}

		$data = isset( $claims['data'] ) && is_array( $claims['data'] ) ? $claims['data'] : array();
		$data = $this->sanitize_attribution_token_data( $data );
		if ( empty( $data ) ) {
			return new WP_Error( 'invalid_token_data', 'Invalid attribution token data', array( 'status' => 401 ) );
		}

		$claims['data'] = $data;
		return $claims;
	}

	/**
	 * Attribution token TTL.
	 *
	 * @return int
	 */
	private function get_attribution_token_ttl(): int {
		$options = Attribution_Settings::get_all();
		$days    = isset( $options['cookie_days'] ) ? absint( $options['cookie_days'] ) : 90;
		$days    = max( 1, min( 90, $days ) );
		$ttl     = $days * DAY_IN_SECONDS;
		$ttl     = (int) apply_filters( 'clicutcl_attribution_token_ttl', $ttl );

		return max( HOUR_IN_SECONDS, min( 90 * DAY_IN_SECONDS, $ttl ) );
	}

	/**
	 * Signing key for attribution tokens.
	 *
	 * @return string
	 */
	private function attribution_token_signing_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|ct_attribution' );
	}

	/**
	 * Current site host for token scoping.
	 *
	 * @return string
	 */
	private function current_token_host(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( sanitize_text_field( $host ) );
	}

	/**
	 * Validate whether host is allowed for this site policy.
	 *
	 * @param string $token_host Host from token.
	 * @return bool
	 */
	private function is_allowed_token_host( string $token_host ): bool {
		$token_host = strtolower( trim( $token_host ) );
		if ( '' === $token_host ) {
			return false;
		}

		$current_host = $this->current_token_host();
		if ( '' === $current_host ) {
			return false;
		}
		if ( $token_host === $current_host ) {
			return true;
		}

		$allow_subdomains = (bool) apply_filters( 'clicutcl_v2_allow_subdomain_tokens', true, $token_host, $current_host );
		if ( $allow_subdomains && ( str_ends_with( $token_host, '.' . $current_host ) || str_ends_with( $current_host, '.' . $token_host ) ) ) {
			return true;
		}

		$allowed_hosts = apply_filters( 'clicutcl_v2_allowed_token_hosts', array(), $current_host );
		if ( class_exists( 'CLICUTCL\\Tracking\\Settings' ) ) {
			$settings_allowed = Tracking_Settings::get()['security']['allowed_token_hosts'] ?? array();
			if ( is_array( $settings_allowed ) ) {
				$allowed_hosts = array_merge( $settings_allowed, is_array( $allowed_hosts ) ? $allowed_hosts : array() );
			}
		}
		if ( is_string( $allowed_hosts ) ) {
			$allowed_hosts = preg_split( '/[\r\n,\s]+/', $allowed_hosts );
		}
		if ( is_array( $allowed_hosts ) ) {
			foreach ( $allowed_hosts as $host ) {
				$host = strtolower( trim( sanitize_text_field( (string) $host ) ) );
				if ( '' === $host ) {
					continue;
				}
				if ( $host === $token_host ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Base64URL encode helper.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	private function base64url_encode( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64URL encoding for signed token transport, not code obfuscation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64URL decode helper.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	private function base64url_decode( string $data ): string {
		$remainder = strlen( $data ) % 4;
		if ( $remainder > 0 ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Base64URL decoding for signed token transport, not code obfuscation.
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
		if ( false === $decoded ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging behind WP_DEBUG for token parsing failures.
				error_log( 'ClickTrail: base64url_decode failed for attribution token payload.' );
			}
			return '';
		}
		return $decoded;
	}
}
