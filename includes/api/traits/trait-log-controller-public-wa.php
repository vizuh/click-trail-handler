<?php
/**
 * Public WhatsApp payload and token helpers for Log_Controller.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Api;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Log_Controller_Public_WA_Trait {
	/**
	 * Verify wp_rest nonce if provided.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return bool
	 */
	private function verify_rest_nonce( $request ) {
		// Verify Nonce (passed in header X-WP-Nonce or _wpnonce param).
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_header( 'x-wp-nonce' );
		}
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce ) {
			return false;
		}

		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Validate timestamp drift.
	 *
	 * @param int $timestamp Timestamp.
	 * @return bool
	 */
	private function is_timestamp_valid( $timestamp ) {
		$now = time();
		return abs( $now - (int) $timestamp ) <= self::TIMESTAMP_DRIFT;
	}

	/**
	 * Sanitize public payload (allowlist + caps).
	 *
	 * @param \WP_REST_Request|array $input Request or params.
	 * @return array|\WP_Error
	 */
	private function sanitize_public_payload( $input ) {
		$params = $input instanceof \WP_REST_Request ? $input->get_json_params() : $input;
		$params = is_array( $params ) ? $params : array();

		if ( isset( $params['event_id'] ) && ( is_array( $params['event_id'] ) || is_object( $params['event_id'] ) ) ) {
			return new WP_Error( 'invalid_event_id', 'Invalid event_id', array( 'status' => 400 ) );
		}
		$event_id = isset( $params['event_id'] ) ? sanitize_text_field( (string) $params['event_id'] ) : '';

		if ( isset( $params['token'] ) && ( is_array( $params['token'] ) || is_object( $params['token'] ) ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}
		$token = isset( $params['token'] ) ? sanitize_text_field( (string) $params['token'] ) : '';

		if ( isset( $params['ts'] ) && ( is_array( $params['ts'] ) || is_object( $params['ts'] ) ) ) {
			return new WP_Error( 'invalid_timestamp', 'Invalid timestamp', array( 'status' => 400 ) );
		}
		$ts = isset( $params['ts'] ) ? absint( $params['ts'] ) : 0;

		if ( ! $event_id || strlen( $event_id ) > 64 ) {
			return new WP_Error( 'invalid_event_id', 'Invalid event_id', array( 'status' => 400 ) );
		}

		if ( ! $ts ) {
			return new WP_Error( 'invalid_timestamp', 'Invalid timestamp', array( 'status' => 400 ) );
		}

		if ( ! $token || strlen( $token ) > 2048 ) {
			return new WP_Error( 'invalid_token', 'Missing or invalid token', array( 'status' => 401 ) );
		}

		if ( isset( $params['page_path'] ) && ( is_array( $params['page_path'] ) || is_object( $params['page_path'] ) ) ) {
			return new WP_Error( 'invalid_page_path', 'Invalid page_path', array( 'status' => 400 ) );
		}
		$page_path = isset( $params['page_path'] ) ? $this->normalize_path( $params['page_path'] ) : '';
		if ( ! $page_path && isset( $params['wa_location'] ) ) {
			$page_path = $this->normalize_path( $params['wa_location'] );
		}
		$page_path = $page_path ? sanitize_text_field( $page_path ) : '';

		if ( isset( $params['wa_target_type'] ) && ( is_array( $params['wa_target_type'] ) || is_object( $params['wa_target_type'] ) ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}
		if ( isset( $params['wa_target_path'] ) && ( is_array( $params['wa_target_path'] ) || is_object( $params['wa_target_path'] ) ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}
		$wa_target_type = isset( $params['wa_target_type'] ) ? sanitize_text_field( (string) $params['wa_target_type'] ) : '';
		$wa_target_path = isset( $params['wa_target_path'] ) ? sanitize_text_field( (string) $params['wa_target_path'] ) : '';

		if ( ! $wa_target_type || ! $wa_target_path ) {
			$normalized = $this->normalize_wa_target_from_params( $params );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			$wa_target_type = $normalized['wa_target_type'];
			$wa_target_path = $normalized['wa_target_path'];
		}

		$allowed_hosts = array(
			'wa.me'             => true,
			'whatsapp.com'      => true,
			'api.whatsapp.com'  => true,
			'web.whatsapp.com'  => true,
		);
		if ( ! isset( $allowed_hosts[ $wa_target_type ] ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}

		$wa_target_path = $this->normalize_path( $wa_target_path );
		$wa_target_path = preg_replace( '/\d+/', 'redacted', $wa_target_path );
		$wa_target_path = sanitize_text_field( $wa_target_path );

		$attribution = isset( $params['attribution'] ) ? $this->sanitize_attribution_subset( $params['attribution'] ) : array();

		return array(
			'event_id'       => $event_id,
			'token'          => $token,
			'ts'             => $ts,
			'page_path'      => $page_path,
			'wa_target_type' => $wa_target_type,
			'wa_target_path' => $wa_target_path,
			'attribution'    => $attribution,
		);
	}

	/**
	 * Sanitize attribution subset (allowlist + length caps).
	 *
	 * @param array $attribution Raw attribution.
	 * @return array
	 */
	private function sanitize_attribution_subset( $attribution ) {
		if ( ! is_array( $attribution ) ) {
			return array();
		}

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
			'lt_epik',
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
			'epik',
		);

		$clean = array();
		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $attribution[ $key ] ) ) {
				continue;
			}
			$value = $attribution[ $key ];
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
			$clean[ $key ] = $value;
		}

		$clean = $this->normalize_click_ids( $clean );

		$canonical = array(
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
			'epik',
		);

		$normalized = array();
		foreach ( $canonical as $key ) {
			if ( isset( $clean[ $key ] ) && '' !== $clean[ $key ] ) {
				$normalized[ $key ] = $clean[ $key ];
			}
		}

		return $normalized;
	}

	/**
	 * Normalize paths (strip query/fragment, ensure leading slash).
	 *
	 * @param string $value Raw path or URL.
	 * @return string
	 */
	private function normalize_path( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$parts = wp_parse_url( $value );
		if ( ! $parts ) {
			$parts = wp_parse_url( 'https://example.com' . $value );
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( '' === $path ) {
			$path = '/';
		}

		if ( strlen( $path ) > 256 ) {
			$path = substr( $path, 0, 256 );
		}

		return $path;
	}

	/**
	 * Normalize WA target from legacy wa_href if provided.
	 *
	 * @param array $params Raw params.
	 * @return array|\WP_Error
	 */
	private function normalize_wa_target_from_params( $params ) {
		if ( isset( $params['wa_href'] ) && ( is_array( $params['wa_href'] ) || is_object( $params['wa_href'] ) ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}

		$wa_href = isset( $params['wa_href'] ) ? esc_url_raw( (string) $params['wa_href'] ) : '';
		if ( ! $wa_href ) {
			return new WP_Error( 'missing_target', 'Missing WhatsApp target', array( 'status' => 400 ) );
		}

		$parts = wp_parse_url( $wa_href );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return new WP_Error( 'invalid_target', 'Invalid WhatsApp target', array( 'status' => 400 ) );
		}

		$host = strtolower( $parts['host'] );
		$path = isset( $parts['path'] ) ? $parts['path'] : '';

		return array(
			'wa_target_type' => sanitize_text_field( (string) $host ),
			'wa_target_path' => sanitize_text_field( (string) $path ),
		);
	}

	/**
	 * Normalize first/last-touch click IDs into canonical keys.
	 *
	 * @param array $attribution Attribution payload.
	 * @return array
	 */
	private function normalize_click_ids( $attribution ) {
		$attribution = is_array( $attribution ) ? $attribution : array();
		$keys        = array( 'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid', 'twclid', 'li_fat_id', 'sccid', 'epik' );

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
			} elseif ( ! empty( $attribution['lt_sc_click_id'] ) ) {
				$attribution['sccid'] = $attribution['lt_sc_click_id'];
			} elseif ( ! empty( $attribution['ft_sc_click_id'] ) ) {
				$attribution['sccid'] = $attribution['ft_sc_click_id'];
			}
		}

		return $attribution;
	}

	/**
	 * Mint short-lived signed token for WA logging endpoint.
	 *
	 * @return string
	 */
	public function create_public_wa_token() {
		$now    = time();
		$ttl    = $this->get_token_ttl();
		$claims = array(
			'v'       => 1,
			'iat'     => $now,
			'exp'     => $now + $ttl,
			'nonce'   => wp_generate_uuid4(),
			'site'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'blog_id' => (int) get_current_blog_id(),
		);

		$json = wp_json_encode( $claims );
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}

		$payload = $this->base64url_encode( $json );
		$sig     = hash_hmac( 'sha256', $payload, $this->get_token_signing_key(), true );
		return $payload . '.' . $this->base64url_encode( $sig );
	}

	/**
	 * Verify WA signed token.
	 *
	 * @param string $token Raw token.
	 * @return array|\WP_Error
	 */
	private function verify_public_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}

		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}

		list( $payload_b64, $sig_b64 ) = $parts;
		$provided_sig                  = $this->base64url_decode( $sig_b64 );
		if ( false === $provided_sig ) {
			return new WP_Error( 'invalid_token', 'Invalid token', array( 'status' => 401 ) );
		}

		$expected_sig = hash_hmac( 'sha256', $payload_b64, $this->get_token_signing_key(), true );
		if ( ! hash_equals( $expected_sig, $provided_sig ) ) {
			return new WP_Error( 'invalid_signature', 'Invalid token signature', array( 'status' => 401 ) );
		}

		$payload_json = $this->base64url_decode( $payload_b64 );
		$claims       = is_string( $payload_json ) ? json_decode( $payload_json, true ) : null;
		if ( ! is_array( $claims ) ) {
			return new WP_Error( 'invalid_token', 'Invalid token payload', array( 'status' => 401 ) );
		}

		$now = time();
		$exp = isset( $claims['exp'] ) ? absint( $claims['exp'] ) : 0;
		$iat = isset( $claims['iat'] ) ? absint( $claims['iat'] ) : 0;
		if ( ! $exp || ! $iat || $exp < $now || $iat > ( $now + 60 ) ) {
			return new WP_Error( 'token_expired', 'Token expired', array( 'status' => 401 ) );
		}

		$site_host = isset( $claims['site'] ) ? strtolower( sanitize_text_field( (string) $claims['site'] ) ) : '';
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( ! $site_host || $site_host !== $home_host ) {
			return new WP_Error( 'token_site_mismatch', 'Token site mismatch', array( 'status' => 401 ) );
		}

		$blog_id = isset( $claims['blog_id'] ) ? absint( $claims['blog_id'] ) : 0;
		if ( $blog_id !== (int) get_current_blog_id() ) {
			return new WP_Error( 'token_blog_mismatch', 'Token blog mismatch', array( 'status' => 401 ) );
		}

		$nonce = isset( $claims['nonce'] ) ? sanitize_text_field( (string) $claims['nonce'] ) : '';
		if ( '' === $nonce || strlen( $nonce ) > 128 ) {
			return new WP_Error( 'invalid_token_nonce', 'Invalid token nonce', array( 'status' => 401 ) );
		}

		$claims['nonce'] = $nonce;
		$claims['exp']   = $exp;
		return $claims;
	}

	/**
	 * Enforce nonce replay limits for signed tokens.
	 *
	 * @param array $claims Verified claims.
	 * @return true|\WP_Error
	 */
	private function check_token_nonce_limit( $claims ) {
		$nonce = isset( $claims['nonce'] ) ? (string) $claims['nonce'] : '';
		if ( '' === $nonce ) {
			return new WP_Error( 'invalid_token_nonce', 'Invalid token nonce', array( 'status' => 401 ) );
		}

		$limit = $this->get_nonce_replay_limit();
		if ( $limit < 1 ) {
			return true;
		}

		$key   = 'clicutcl_wa_nonce_' . md5( $nonce );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error( 'nonce_replay_limited', 'Too many requests for token nonce', array( 'status' => 429 ) );
		}

		$ttl = max( 60, (int) $claims['exp'] - time() );
		set_transient( $key, $count + 1, $ttl );

		return true;
	}

	/**
	 * Get token TTL with bounds.
	 *
	 * @return int
	 */
	private function get_token_ttl() {
		$ttl = (int) apply_filters( 'clicutcl_wa_token_ttl', self::TOKEN_TTL_DEFAULT );
		if ( $ttl < 60 ) {
			$ttl = 60;
		}

		return min( self::TOKEN_TTL_MAX, $ttl );
	}

	/**
	 * Allowed requests per token nonce.
	 *
	 * @return int
	 */
	private function get_nonce_replay_limit() {
		$limit = (int) apply_filters( 'clicutcl_wa_token_nonce_limit', self::NONCE_REPLAY_LIMIT_DEFAULT );
		return max( 0, min( 1000, $limit ) );
	}

	/**
	 * HMAC key material for WA token signing.
	 *
	 * @return string
	 */
	private function get_token_signing_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) );
	}

	/**
	 * Base64-url encode helper.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64-url decode helper.
	 *
	 * @param string $value Encoded string.
	 * @return string|false
	 */
	private function base64url_decode( $value ) {
		$value = strtr( (string) $value, '-_', '+/' );
		$pad   = strlen( $value ) % 4;
		if ( 0 !== $pad ) {
			$value .= str_repeat( '=', 4 - $pad );
		}

		return base64_decode( $value, true );
	}
}
