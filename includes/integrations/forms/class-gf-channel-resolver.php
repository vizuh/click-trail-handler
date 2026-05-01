<?php
/**
 * Gravity Forms Channel Resolver
 *
 * Server-side mirror of the JS `resolveChannelLabel` function in
 * `assets/js/clicutcl-attribution.js`. Used as a fallback at form-submission
 * time when no `ft_channel` is present in the attribution payload (e.g. the
 * page was cached and JS attribution did not run, or the visitor's browser
 * blocked the channel field). The output is stored verbatim in `ct_ft_channel`.
 *
 * Classification rules MUST stay in sync with the JS counterpart. When you
 * change a rule here, also update `resolveChannelLabel` in the JS file, and
 * vice versa.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gf_Channel_Resolver
 *
 * Pure logic — no GF dependency, no WP DB access. Takes a payload + optional
 * referrer and returns a channel label string. This is the unit-tested surface.
 */
class Gf_Channel_Resolver {

	/**
	 * Resolve a channel label from an attribution payload and optional referrer.
	 *
	 * Priority order matches the JS classifier:
	 *   1. Paid click IDs (Google, Microsoft, LinkedIn, X, Reddit, TikTok,
	 *      Pinterest, Snapchat) → respective Ads label.
	 *   2. `fbclid` plus a paid utm_medium (`cpc`, `paid_social`, `paid`) →
	 *      `Facebook Ads`.
	 *   3. Email-platform signals (`mc_cid`/`mc_eid`, `utm_source` matches
	 *      `hubspot`/`pardot`/`constantcontact`).
	 *   4. Referrer-based AI assistant detection (ChatGPT, Perplexity, Copilot,
	 *      Gemini, Claude, Grok, DeepSeek). Checked BEFORE the generic Google
	 *      check so `gemini.google.com` does not match `Google Organic`.
	 *   5. Referrer-based organic search and social.
	 *   6. `fbclid` without a paid medium → `Facebook Organic`.
	 *   7. No signal → `Unknown`.
	 *
	 * @param array  $payload      Attribution payload (cookie/session shape;
	 *                             supports both top-level and `ft_*` / `lt_*`
	 *                             prefixed keys).
	 * @param string $referrer     Optional raw referrer URL. Empty string when
	 *                             called from contexts without referrer (e.g.
	 *                             GF submission server-side fallback).
	 * @param string $current_host Optional current site host for internal-
	 *                             referrer detection. Production callers pass
	 *                             `$_SERVER['HTTP_HOST']`. Tests pass an
	 *                             explicit value.
	 * @return string Channel label.
	 */
	public function resolve( array $payload, string $referrer = '', string $current_host = '' ): string {
		$has = static function ( $key ) use ( $payload ) {
			return ! empty( $payload[ $key ] ) || ! empty( $payload[ 'ft_' . $key ] );
		};

		// 1. Paid click IDs.
		if ( $has( 'gclid' ) || $has( 'gbraid' ) || $has( 'wbraid' ) ) {
			return 'Google Ads';
		}
		if ( $has( 'msclkid' ) ) {
			return 'Microsoft Ads';
		}
		if ( $has( 'li_fat_id' ) ) {
			return 'LinkedIn Ads';
		}
		if ( $has( 'twclid' ) ) {
			return 'X Ads';
		}
		if ( $has( 'rdt_cid' ) ) {
			return 'Reddit Ads';
		}
		if ( $has( 'ttclid' ) ) {
			return 'TikTok Ads';
		}
		if ( $has( 'pin_cid' ) || $has( 'epik' ) ) {
			return 'Pinterest Ads';
		}
		if ( $has( 'snap_cid' ) || $has( 'sccid' ) ) {
			return 'Snapchat Ads';
		}
		if ( $has( 'dclid' ) ) {
			return 'Display & Video 360';
		}

		// 2. fbclid only when a paid medium is present.
		$utm_medium = $this->lower_string( $payload, 'utm_medium', 'ft_medium' );
		if ( $has( 'fbclid' ) && in_array( $utm_medium, array( 'cpc', 'paid_social', 'paid' ), true ) ) {
			return 'Facebook Ads';
		}

		// 3. Email platforms.
		if ( $has( 'mc_cid' ) || $has( 'mc_eid' ) ) {
			return 'Mailchimp';
		}
		$utm_source = $this->lower_string( $payload, 'utm_source', 'ft_source' );
		if ( 'hubspot' === $utm_source || ! empty( $payload['hs_cta'] ) ) {
			return 'HubSpot';
		}
		if ( 'pardot' === $utm_source || ! empty( $payload['pi_u'] ) ) {
			return 'Salesforce Pardot';
		}
		if ( 'constantcontact' === $utm_source ) {
			return 'Constant Contact';
		}

		// 4–5. Referrer-based classification.
		$ref_host = $this->normalize_hostname( $this->parse_host( $referrer ) );
		$ref_path = $this->parse_path( $referrer );

		if ( '' !== $ref_host ) {
			// Internal referrer (same site) is treated as direct/no signal.
			$normalized_current = $this->normalize_hostname( $current_host );
			if ( '' !== $normalized_current && $this->are_related_hosts( $ref_host, $normalized_current ) ) {
				$ref_host = '';
			}
		}

		if ( '' !== $ref_host ) {
			// AI assistants — checked BEFORE search engines so that
			// gemini.google.com does not match `Google Organic`.
			if ( $this->host_matches_domain( $ref_host, 'chatgpt.com' ) || $this->host_matches_domain( $ref_host, 'chat.openai.com' ) ) {
				return 'ChatGPT';
			}
			if ( $this->host_matches_domain( $ref_host, 'perplexity.ai' ) ) {
				return 'Perplexity';
			}
			if ( $this->host_matches_domain( $ref_host, 'copilot.microsoft.com' )
				|| ( $this->host_matches_domain( $ref_host, 'bing.com' ) && 0 === strpos( $ref_path, '/chat' ) )
			) {
				return 'Microsoft Copilot';
			}
			if ( $this->host_matches_domain( $ref_host, 'gemini.google.com' ) ) {
				return 'Gemini';
			}
			if ( $this->host_matches_domain( $ref_host, 'claude.ai' ) ) {
				return 'Claude';
			}
			if ( $this->host_matches_domain( $ref_host, 'grok.com' )
				|| ( $this->host_matches_domain( $ref_host, 'x.com' ) && 0 === strpos( $ref_path, '/i/grok' ) )
			) {
				return 'Grok';
			}
			if ( $this->host_matches_domain( $ref_host, 'deepseek.com' ) ) {
				return 'DeepSeek';
			}

			// Organic search.
			if ( $this->host_matches_label( $ref_host, 'google' ) ) {
				return 'Google Organic';
			}
			if ( $this->host_matches_domain( $ref_host, 'bing.com' ) ) {
				return 'Bing Organic';
			}
			if ( $this->host_matches_label( $ref_host, 'yahoo' ) ) {
				return 'Yahoo';
			}
			if ( $this->host_matches_domain( $ref_host, 'duckduckgo.com' ) ) {
				return 'DuckDuckGo';
			}
			if ( $this->host_matches_label( $ref_host, 'yandex' ) ) {
				return 'Yandex';
			}

			// Organic social.
			if ( $this->host_matches_domain( $ref_host, 'facebook.com' ) || $this->host_matches_domain( $ref_host, 'fb.com' ) ) {
				return 'Facebook Organic';
			}
			if ( $this->host_matches_domain( $ref_host, 'instagram.com' ) ) {
				return 'Instagram Organic';
			}
			if ( $this->host_matches_domain( $ref_host, 'linkedin.com' ) || $this->host_matches_domain( $ref_host, 'lnkd.in' ) ) {
				return 'LinkedIn Organic';
			}
			if ( $this->host_matches_domain( $ref_host, 'twitter.com' )
				|| $this->host_matches_domain( $ref_host, 't.co' )
				|| $this->host_matches_domain( $ref_host, 'x.com' )
			) {
				return 'X Organic';
			}
			if ( $this->host_matches_domain( $ref_host, 'reddit.com' ) ) {
				return 'Reddit Organic';
			}
			if ( $this->host_matches_domain( $ref_host, 'tiktok.com' ) ) {
				return 'TikTok Organic';
			}
			if ( $this->host_matches_domain( $ref_host, 'pinterest.com' ) ) {
				return 'Pinterest Organic';
			}
			if ( $this->host_matches_domain( $ref_host, 'snapchat.com' ) ) {
				return 'Snapchat Organic';
			}
		}

		// 6. fbclid without paid medium → organic Facebook.
		if ( $has( 'fbclid' ) ) {
			return 'Facebook Organic';
		}

		// 7. No signal.
		return 'Unknown';
	}

	/**
	 * Read a string from the payload at $key or fallback $alt_key, lowercased.
	 *
	 * @param array  $payload Payload.
	 * @param string $key     Primary key.
	 * @param string $alt_key Fallback key.
	 * @return string
	 */
	private function lower_string( array $payload, string $key, string $alt_key ): string {
		$value = '';
		if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
			$value = (string) $payload[ $key ];
		} elseif ( isset( $payload[ $alt_key ] ) && is_scalar( $payload[ $alt_key ] ) ) {
			$value = (string) $payload[ $alt_key ];
		}
		return strtolower( sanitize_text_field( $value ) );
	}

	/**
	 * Normalize a hostname: lowercase, drop trailing dots and leading `www.`.
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	private function normalize_hostname( string $host ): string {
		$host = strtolower( trim( $host ) );
		$host = rtrim( $host, '.' );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	/**
	 * Extract the hostname component from a URL string.
	 *
	 * @param string $url URL.
	 * @return string Hostname or empty string.
	 */
	private function parse_host( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$parsed = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure URL parsing, no WP-specific behavior needed.
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}
		return (string) $parsed['host'];
	}

	/**
	 * Extract the path component from a URL string.
	 *
	 * @param string $url URL.
	 * @return string Path or empty string.
	 */
	private function parse_path( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$parsed = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure URL parsing, no WP-specific behavior needed.
		if ( ! is_array( $parsed ) || empty( $parsed['path'] ) ) {
			return '';
		}
		return (string) $parsed['path'];
	}

	/**
	 * Whether $host equals $domain or is a subdomain of $domain.
	 *
	 * @param string $host   Host (already normalized).
	 * @param string $domain Domain to match.
	 * @return bool
	 */
	private function host_matches_domain( string $host, string $domain ): bool {
		$domain = $this->normalize_hostname( $domain );
		if ( '' === $host || '' === $domain ) {
			return false;
		}
		return $host === $domain || $this->ends_with( $host, '.' . $domain );
	}

	/**
	 * Whether $host contains $label as a domain segment (e.g. `google` matches
	 * `google.com.br` but not `notgoogle.com`).
	 *
	 * @param string $host  Host (already normalized).
	 * @param string $label Label.
	 * @return bool
	 */
	private function host_matches_label( string $host, string $label ): bool {
		$label = strtolower( trim( $label ) );
		if ( '' === $host || '' === $label ) {
			return false;
		}
		$pattern = '/(^|\.)' . preg_quote( $label, '/' ) . '\./';
		return 1 === preg_match( $pattern, $host );
	}

	/**
	 * Whether two hosts are related (same host or one is a subdomain of the
	 * other). Used to detect internal referrers.
	 *
	 * @param string $first  First host (already normalized).
	 * @param string $second Second host (already normalized).
	 * @return bool
	 */
	private function are_related_hosts( string $first, string $second ): bool {
		if ( '' === $first || '' === $second ) {
			return false;
		}
		return $first === $second
			|| $this->ends_with( $first, '.' . $second )
			|| $this->ends_with( $second, '.' . $first );
	}

	/**
	 * String ends_with helper (PHP 8.0+ has str_ends_with; written here for
	 * portability and to avoid a dependency).
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	private function ends_with( string $haystack, string $needle ): bool {
		$len = strlen( $needle );
		if ( 0 === $len ) {
			return true;
		}
		return substr( $haystack, -$len ) === $needle;
	}
}
