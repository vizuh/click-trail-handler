<?php
/**
 * Deterministic attribution readiness and UTM analysis.
 *
 * This class is deliberately pure: it does not read WordPress state, persist
 * identifiers, rewrite URLs, or decide whether a provider delivery is live.
 * It returns machine-readable evidence for a later diagnostics surface.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Intelligence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyze a single attribution payload without inventing campaign values.
 */
class Attribution_Readiness_Analyzer {

	/**
	 * Minimum campaign-labeling fields.
	 *
	 * These are structural requirements, not a site-specific naming policy.
	 *
	 * @var string[]
	 */
	private const CORE_FIELDS = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
	);

	/**
	 * Optional UTM context fields.
	 *
	 * @var string[]
	 */
	private const OPTIONAL_FIELDS = array(
		'utm_term',
		'utm_content',
		'utm_id',
		'utm_source_platform',
		'utm_creative_format',
		'utm_marketing_tactic',
	);

	/**
	 * Click-ID keys and their deterministic platform signal.
	 *
	 * `source_value` is a canonical internal platform key. It is a safe default
	 * suggestion only; a site may configure a different UTM alias later.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const CLICK_ID_SIGNALS = array(
		'gclid'     => array(
			'platform'     => 'google',
			'source_value' => 'google',
			'signal_type'  => 'ad_click',
		),
		'wbraid'    => array(
			'platform'     => 'google',
			'source_value' => 'google',
			'signal_type'  => 'ad_click',
		),
		'gbraid'    => array(
			'platform'     => 'google',
			'source_value' => 'google',
			'signal_type'  => 'ad_click',
		),
		'fbclid'    => array(
			'platform'     => 'meta',
			'source_value' => 'meta',
			'signal_type'  => 'click_id',
		),
		'msclkid'   => array(
			'platform'     => 'microsoft',
			'source_value' => 'microsoft',
			'signal_type'  => 'ad_click',
		),
		'ttclid'    => array(
			'platform'     => 'tiktok',
			'source_value' => 'tiktok',
			'signal_type'  => 'ad_click',
		),
		'twclid'    => array(
			'platform'     => 'x',
			'source_value' => 'x',
			'signal_type'  => 'click_id',
		),
		'li_fat_id' => array(
			'platform'     => 'linkedin',
			'source_value' => 'linkedin',
			'signal_type'  => 'ad_click',
		),
		'sccid'     => array(
			'platform'     => 'snapchat',
			'source_value' => 'snapchat',
			'signal_type'  => 'ad_click',
		),
		'epik'      => array(
			'platform'     => 'pinterest',
			'source_value' => 'pinterest',
			'signal_type'  => 'click_id',
		),
		'rdt_cid'   => array(
			'platform'     => 'reddit',
			'source_value' => 'reddit',
			'signal_type'  => 'click_id',
		),
		'pin_cid'   => array(
			'platform'     => 'pinterest',
			'source_value' => 'pinterest',
			'signal_type'  => 'click_id',
		),
		'snap_cid'  => array(
			'platform'     => 'snapchat',
			'source_value' => 'snapchat',
			'signal_type'  => 'click_id',
		),
		'mc_cid'    => array(
			'platform'     => 'mailchimp',
			'source_value' => 'mailchimp',
			'signal_type'  => 'campaign_click',
		),
		'mc_eid'    => array(
			'platform'     => 'mailchimp',
			'source_value' => 'mailchimp',
			'signal_type'  => 'campaign_click',
		),
		'dclid'     => array(
			'platform'     => 'display_video_360',
			'source_value' => 'display_video_360',
			'signal_type'  => 'ad_click',
		),
	);

	/**
	 * Legacy aliases accepted for the Snapchat click ID.
	 *
	 * @var array<string,string>
	 */
	private const CLICK_ID_ALIASES = array(
		'sc_click_id' => 'sccid',
	);

	/**
	 * Known referrer domains. A referrer is source evidence, not proof of paid
	 * traffic, so it never implies a paid medium.
	 *
	 * @var array<string,string>
	 */
	private const REFERRER_DOMAINS = array(
		'google.com'     => 'google',
		'bing.com'       => 'bing',
		'yahoo.com'      => 'yahoo',
		'duckduckgo.com' => 'duckduckgo',
		'linkedin.com'   => 'linkedin',
		'lnkd.in'        => 'linkedin',
		'facebook.com'   => 'meta',
		'fb.com'         => 'meta',
		'instagram.com'  => 'instagram',
		'tiktok.com'     => 'tiktok',
		'pinterest.com'  => 'pinterest',
		'reddit.com'     => 'reddit',
		'x.com'          => 'x',
		't.co'           => 'x',
	);

	/**
	 * Analyze a payload and optional referrer.
	 *
	 * The analyzer accepts raw query names (`utm_source`) and ClickTrail's
	 * flattened touch names (`source`, `ft_source`, `lt_source`). First/last
	 * touch fields are not treated as conflicts: the current value is selected
	 * from the latest touch, then the direct value, then first touch.
	 *
	 * @param array<string,mixed> $payload Attribution payload.
	 * @param string              $referrer Optional referrer URL.
	 * @param string              $current_host Current site host for internal-referrer filtering.
	 * @param array<string,mixed> $options Optional policy options.
	 * @return array<string,mixed> Machine-readable analysis.
	 */
	public function analyze( array $payload, string $referrer = '', string $current_host = '', array $options = array() ): array {
		$normalized        = $this->normalize_payload_keys( $payload );
		$signals           = $this->detect_click_signals( $normalized );
		$referrer_evidence = $this->detect_referrer( $referrer, $current_host );
		$source_evidence   = $this->resolve_source_evidence( $signals, $referrer_evidence );

		$fields = array();
		$issues = array();

		foreach ( self::CORE_FIELDS as $field ) {
			$result           = $this->analyze_field( $field, $normalized, $source_evidence );
			$fields[ $field ] = $result;
			if ( ! empty( $result['issue'] ) ) {
				$issues[] = $result['issue'];
			}
		}

		foreach ( self::OPTIONAL_FIELDS as $field ) {
			$fields[ $field ] = $this->analyze_optional_field( $field, $normalized );
		}

		if ( count( $source_evidence['platforms'] ) > 1 ) {
			$issues[] = array(
				'code'     => 'multiple_platform_signals',
				'field'    => 'source',
				'severity' => 'attention',
				'basis'    => 'multiple_click_id_platforms',
			);
		}

		if ( ! empty( $source_evidence['platform'] ) && $this->has_source_conflict( $fields['utm_source'], $source_evidence['platform'] ) ) {
			$issues[] = array(
				'code'     => 'source_signal_conflict',
				'field'    => 'utm_source',
				'severity' => 'attention',
				'basis'    => $source_evidence['basis'],
				'platform' => $source_evidence['platform'],
			);
		}

		$recommendations = $this->build_recommendations( $fields, $source_evidence, $options );
		$status          = $this->resolve_status( $issues );

		return array(
			'status'          => $status,
			'fields'          => $fields,
			'click_ids'       => array(
				'keys'      => $signals['keys'],
				'platforms' => $signals['platforms'],
			),
			'source_evidence' => array(
				'platform'      => $source_evidence['platform'],
				'basis'         => $source_evidence['basis'],
				'signal_type'   => $source_evidence['signal_type'],
				'referrer_host' => $referrer_evidence['host'],
			),
			'issues'          => $issues,
			'recommendations' => $recommendations,
		);
	}

	/**
	 * Return the default core and optional field lists for policy UIs.
	 *
	 * @return array<string,array<int,string>> Field policy.
	 */
	public static function field_policy(): array {
		return array(
			'required' => self::CORE_FIELDS,
			'optional' => self::OPTIONAL_FIELDS,
		);
	}

	/**
	 * Return platform keys eligible for a site-specific source alias.
	 *
	 * @return string[]
	 */
	public static function source_alias_platforms(): array {
		$platforms = array();
		foreach ( self::CLICK_ID_SIGNALS as $signal ) {
			$platforms[] = $signal['platform'];
		}
		return array_values( array_unique( $platforms ) );
	}

	/**
	 * Build a bounded copy-only test URL from allowlisted analyzer output.
	 *
	 * The builder never reads WordPress state, includes click IDs, or invents
	 * missing medium/campaign values. Callers decide whether to display or copy
	 * the returned URL; this method does not mutate navigation.
	 *
	 * @param string              $base_url Base HTTP(S) URL without query or fragment.
	 * @param array<string,mixed> $analysis Allowlisted analyzer output.
	 * @return string|null
	 */
	public function build_test_url( string $base_url, array $analysis ): ?string {
		if ( trim( $base_url ) !== $base_url || '' === $base_url || 2048 < strlen( $base_url ) ) {
			return null;
		}
		if ( 1 === preg_match( '/[\x00-\x20\x7F]/', $base_url ) || false !== strpos( $base_url, '\\' ) || false !== strpos( $base_url, '?' ) || false !== strpos( $base_url, '#' ) ) {
			return null;
		}
		if ( false === filter_var( $base_url, FILTER_VALIDATE_URL ) ) {
			return null;
		}

		$parts = parse_url( $base_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure parsing; no remote request.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		foreach ( array( 'user', 'pass', 'port', 'query', 'fragment' ) as $forbidden_part ) {
			if ( array_key_exists( $forbidden_part, $parts ) ) {
				return null;
			}
		}

		$host = (string) $parts['host'];
		if ( false === filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) && false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$fields = isset( $analysis['fields'] ) && is_array( $analysis['fields'] ) ? $analysis['fields'] : array();
		$params = array();
		foreach ( self::CORE_FIELDS as $field ) {
			$row    = isset( $fields[ $field ] ) && is_array( $fields[ $field ] ) ? $fields[ $field ] : array();
			$status = isset( $row['status'] ) && is_string( $row['status'] ) ? $row['status'] : '';
			if ( in_array( $status, array( 'invalid', 'invalid_macro' ), true ) ) {
				return null;
			}
			if ( 'present' !== $status ) {
				continue;
			}

			$value = $this->safe_test_parameter_value( $row['observed_value'] ?? null );
			if ( null === $value ) {
				return null;
			}
			$params[ $field ] = $value;
		}

		if ( ! isset( $params['utm_source'] ) ) {
			$recommendations = isset( $analysis['recommendations'] ) && is_array( $analysis['recommendations'] ) ? $analysis['recommendations'] : array();
			foreach ( $recommendations as $recommendation ) {
				if ( ! is_array( $recommendation )
					|| 'add_utm_source' !== ( $recommendation['code'] ?? '' )
					|| 'utm_source' !== ( $recommendation['field'] ?? '' )
					|| empty( $recommendation['deterministic'] )
				) {
					continue;
				}
				$value = $this->safe_test_parameter_value( $recommendation['suggested_value'] ?? null );
				if ( null === $value ) {
					return null;
				}
				$params['utm_source'] = $value;
				break;
			}
		}

		if ( empty( $params ) ) {
			return null;
		}

		$query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$url   = $base_url . '?' . $query;
		return strlen( $url ) <= 2304 ? $url : null;
	}

	/**
	 * Validate one allowlisted UTM value for copy-only URL output.
	 *
	 * @param mixed $value Candidate value.
	 * @return string|null
	 */
	private function safe_test_parameter_value( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 255 ) {
			return null;
		}
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || $this->is_unresolved_macro( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Normalize top-level keys without retaining raw identifiers in the result.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,mixed>
	 */
	private function normalize_payload_keys( array $payload ): array {
		$normalized = array();
		foreach ( $payload as $key => $value ) {
			$key = strtolower( trim( (string) $key ) );
			if ( '' === $key ) {
				continue;
			}
			$normalized[ $key ] = $value;
		}
		return $normalized;
	}

	/**
	 * Find a field using raw and flattened ClickTrail aliases.
	 *
	 * @param string              $field Canonical UTM field.
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,mixed>
	 */
	private function read_field( string $field, array $payload ): array {
		$name = substr( $field, 4 );
		// Precedence contract: last touch, then direct value, then first touch.
		// Within a tier, the flattened alias is checked before the raw UTM name.
		$keys = array(
			'lt_' . $name,
			'lt_' . $field,
			$name,
			$field,
			'ft_' . $name,
			'ft_' . $field,
		);

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}

			$tier  = $this->selection_tier_for_key( $key );
			$value = $payload[ $key ];
			if ( ! is_scalar( $value ) ) {
				return array(
					'key'            => $key,
					'present'        => true,
					'value'          => '',
					'invalid'        => true,
					'selection_tier' => $tier,
				);
			}

			$value = $this->bounded_value( $value );
			return array(
				'key'            => $key,
				'present'        => true,
				'value'          => $value,
				'invalid'        => false,
				'selection_tier' => $tier,
			);
		}

		return array(
			'key'            => '',
			'present'        => false,
			'value'          => '',
			'invalid'        => false,
			'selection_tier' => 'none',
		);
	}

	/**
	 * Describe which precedence tier supplied an observed field.
	 *
	 * `direct` means a bare current-payload key, not a traffic-source claim.
	 *
	 * @param string $key Selected payload key.
	 * @return string
	 */
	private function selection_tier_for_key( string $key ): string {
		if ( 0 === strpos( $key, 'lt_' ) ) {
			return 'last_touch';
		}
		if ( 0 === strpos( $key, 'ft_' ) ) {
			return 'first_touch';
		}
		return '' !== $key ? 'direct' : 'none';
	}

	/**
	 * Analyze a required UTM field.
	 *
	 * @param string              $field Canonical field.
	 * @param array<string,mixed> $payload Payload.
	 * @param array<string,mixed> $source_evidence Source evidence.
	 * @return array<string,mixed>
	 */
	private function analyze_field( string $field, array $payload, array $source_evidence ): array {
		$read   = $this->read_field( $field, $payload );
		$result = array(
			'status'         => 'missing_unresolved',
			'required'       => true,
			'observed_key'   => $read['key'],
			'observed_value' => '' !== $read['value'] ? $read['value'] : null,
			'selection_tier' => $read['selection_tier'],
		);

		if ( $read['invalid'] ) {
			$result['status']         = 'invalid';
			$result['selection_tier'] = 'none';
			$result['issue']          = $this->issue( 'invalid_' . $field, $field, 'block', 'non_scalar_value' );
			return $result;
		}

		if ( $read['present'] && '' === $read['value'] ) {
			$result['status']         = 'empty';
			$result['selection_tier'] = 'none';
			$result['issue']          = $this->issue( 'empty_' . $field, $field, 'attention', 'empty_value' );
			return $result;
		}

		if ( $this->is_unresolved_macro( $read['value'] ) ) {
			$result['status']         = 'invalid_macro';
			$result['observed_value'] = null;
			$result['selection_tier'] = 'none';
			$result['issue']          = $this->issue( 'unresolved_macro_' . $field, $field, 'block', 'unresolved_macro' );
			return $result;
		}

		if ( '' !== $read['value'] ) {
			$result['status'] = 'present';
			return $result;
		}

		if ( 'utm_source' === $field && ! empty( $source_evidence['platform'] ) ) {
			$result['status']           = 'missing_inferable';
			$result['suggested_value']  = $source_evidence['source_value'];
			$result['suggestion_basis'] = $source_evidence['basis'];
			$result['deterministic']    = true;
			$result['issue']            = $this->issue( 'missing_inferable_utm_source', $field, 'attention', $source_evidence['basis'] );
			return $result;
		}

		if ( 'utm_source' === $field && ! empty( $source_evidence['referrer_platform'] ) ) {
			$result['status']           = 'missing_referrer_candidate';
			$result['suggested_value']  = $source_evidence['referrer_platform'];
			$result['suggestion_basis'] = 'referrer_host';
			$result['deterministic']    = false;
			$result['issue']            = $this->issue( 'missing_utm_source_referrer_candidate', $field, 'attention', 'referrer_host' );
			return $result;
		}

		$result['issue'] = $this->issue( 'missing_' . $field, $field, 'attention', 'not_inferable' );
		return $result;
	}

	/**
	 * Analyze an optional UTM field without making its absence an issue.
	 *
	 * @param string              $field Field.
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,mixed>
	 */
	private function analyze_optional_field( string $field, array $payload ): array {
		$read           = $this->read_field( $field, $payload );
		$status         = 'optional_missing';
		$selection_tier = $read['selection_tier'];
		if ( $read['invalid'] ) {
			$status         = 'invalid';
			$selection_tier = 'none';
		} elseif ( $read['present'] && '' === $read['value'] ) {
			$status         = 'empty';
			$selection_tier = 'none';
		} elseif ( $this->is_unresolved_macro( $read['value'] ) ) {
			$status         = 'invalid_macro';
			$selection_tier = 'none';
		} elseif ( '' !== $read['value'] ) {
			$status = 'present';
		}

		return array(
			'status'         => $status,
			'required'       => false,
			'observed_key'   => $read['key'],
			'observed_value' => 'present' === $status ? $read['value'] : null,
			'selection_tier' => $selection_tier,
		);
	}

	/**
	 * Detect click-ID presence without returning click-ID values.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,array<int,string>>
	 */
	private function detect_click_signals( array $payload ): array {
		$keys      = array();
		$platforms = array();

		foreach ( self::CLICK_ID_SIGNALS as $key => $signal ) {
			$aliases = array( $key, 'lt_' . $key, 'ft_' . $key );
			foreach ( self::CLICK_ID_ALIASES as $alias => $canonical ) {
				if ( $canonical === $key ) {
					$aliases[] = $alias;
					$aliases[] = 'lt_' . $alias;
					$aliases[] = 'ft_' . $alias;
				}
			}

			foreach ( $aliases as $alias ) {
				if ( ! array_key_exists( $alias, $payload ) || ! is_scalar( $payload[ $alias ] ) || '' === trim( (string) $payload[ $alias ] ) ) {
					continue;
				}
				$keys[]      = $key;
				$platforms[] = $signal['platform'];
				break;
			}
		}

		return array(
			'keys'      => array_values( array_unique( $keys ) ),
			'platforms' => array_values( array_unique( $platforms ) ),
		);
	}

	/**
	 * Detect a known external referrer, excluding related internal hosts.
	 *
	 * @param string $referrer Referrer URL.
	 * @param string $current_host Current host.
	 * @return array<string,string>
	 */
	private function detect_referrer( string $referrer, string $current_host ): array {
		$host    = $this->normalize_host( $this->parse_host( $referrer ) );
		$current = $this->normalize_host( $current_host );
		if ( '' === $host || ( '' !== $current && $this->related_hosts( $host, $current ) ) ) {
			return array(
				'host'     => '',
				'platform' => '',
			);
		}

		foreach ( self::REFERRER_DOMAINS as $domain => $platform ) {
			if ( $this->matches_domain( $host, $domain ) ) {
				return array(
					'host'     => $host,
					'platform' => $platform,
				);
			}
		}

		return array(
			'host'     => $host,
			'platform' => '',
		);
	}

	/**
	 * Resolve click-ID evidence before referrer evidence.
	 *
	 * @param array<string,mixed>  $signals Click signals.
	 * @param array<string,string> $referrer_evidence Referrer evidence.
	 * @return array<string,mixed>
	 */
	private function resolve_source_evidence( array $signals, array $referrer_evidence ): array {
		if ( ! empty( $signals['keys'] ) ) {
			if ( count( $signals['platforms'] ) > 1 ) {
				// Ambiguous attribution: more than one recognized platform
				// signaled. Suppress every deterministic suggestion; the
				// multiple_platform_signals issue carries the diagnosis.
				return array(
					'platform'          => '',
					'source_value'      => '',
					'signal_type'       => '',
					'basis'             => 'multiple_click_id_platforms',
					'platforms'         => $signals['platforms'],
					'referrer_platform' => $referrer_evidence['platform'],
				);
			}
			$key    = $signals['keys'][0];
			$signal = self::CLICK_ID_SIGNALS[ $key ];
			return array(
				'platform'          => $signal['platform'],
				'source_value'      => $signal['source_value'],
				'signal_type'       => $signal['signal_type'],
				'basis'             => 'click_id:' . $key,
				'platforms'         => $signals['platforms'],
				'referrer_platform' => $referrer_evidence['platform'],
			);
		}

		return array(
			'platform'          => '',
			'source_value'      => '',
			'signal_type'       => '' !== $referrer_evidence['platform'] ? 'referrer' : '',
			'basis'             => '' !== $referrer_evidence['platform'] ? 'referrer_host' : '',
			'platforms'         => array(),
			'referrer_platform' => $referrer_evidence['platform'],
		);
	}

	/**
	 * Build non-opinionated recommendations.
	 *
	 * @param array<string,mixed> $fields Field results.
	 * @param array<string,mixed> $source_evidence Source evidence.
	 * @param array<string,mixed> $options Policy options.
	 * @return array<int,array<string,mixed>> Recommendations.
	 */
	private function build_recommendations( array $fields, array $source_evidence, array $options ): array {
		$recommendations = array();
		$source_aliases  = isset( $options['source_aliases'] ) && is_array( $options['source_aliases'] ) ? $options['source_aliases'] : array();

		if ( 'missing_inferable' === $fields['utm_source']['status'] ) {
			$platform          = (string) $source_evidence['platform'];
			$value             = isset( $source_aliases[ $platform ] ) && is_scalar( $source_aliases[ $platform ] )
				? $this->bounded_value( $source_aliases[ $platform ] )
				: (string) $fields['utm_source']['suggested_value'];
			$recommendations[] = array(
				'code'            => 'add_utm_source',
				'field'           => 'utm_source',
				'suggested_value' => $value,
				'deterministic'   => true,
				'basis'           => $fields['utm_source']['suggestion_basis'],
				'value_type'      => empty( $source_aliases[ $platform ] ) ? 'canonical_platform_key' : 'configured_alias',
			);
		}

		foreach ( array( 'utm_medium', 'utm_campaign' ) as $field ) {
			if ( in_array( $fields[ $field ]['status'], array( 'missing_unresolved', 'empty' ), true ) ) {
				$recommendations[] = array(
					'code'          => 'configure_' . $field,
					'field'         => $field,
					'deterministic' => false,
					'basis'         => 'not_inferable_from_click_id',
				);
			}
		}

		return $recommendations;
	}

	/**
	 * Compare an observed source against a known platform without enforcing a
	 * site-specific UTM naming convention.
	 *
	 * @param array<string,mixed> $source_field Source field result.
	 * @param string              $platform Platform key.
	 * @return bool
	 */
	private function has_source_conflict( array $source_field, string $platform ): bool {
		if ( 'present' !== $source_field['status'] || empty( $source_field['observed_value'] ) ) {
			return false;
		}
		$observed = strtolower( preg_replace( '/[^a-z0-9]+/', '', (string) $source_field['observed_value'] ) );
		$expected = strtolower( preg_replace( '/[^a-z0-9]+/', '', $platform ) );
		return '' !== $observed && '' !== $expected && false === strpos( $observed, $expected ) && false === strpos( $expected, $observed );
	}

	/**
	 * Resolve overall status from issue severity.
	 *
	 * @param array<int,array<string,mixed>> $issues Issues.
	 * @return string
	 */
	private function resolve_status( array $issues ): string {
		foreach ( $issues as $issue ) {
			if ( 'block' === ( $issue['severity'] ?? '' ) ) {
				return 'blocked';
			}
		}
		return empty( $issues ) ? 'pass' : 'attention';
	}

	/**
	 * Create a stable issue shape.
	 *
	 * @param string $code Code.
	 * @param string $field Field.
	 * @param string $severity Severity.
	 * @param string $basis Evidence basis.
	 * @return array<string,string>
	 */
	private function issue( string $code, string $field, string $severity, string $basis ): array {
		return array(
			'code'     => $code,
			'field'    => $field,
			'severity' => $severity,
			'basis'    => $basis,
		);
	}

	/**
	 * Bound and sanitize a scalar value for analysis output.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function bounded_value( $value ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value );
		return substr( trim( $value ), 0, 255 );
	}

	/**
	 * Detect an unsubstituted advertising macro.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_unresolved_macro( string $value ): bool {
		return 1 === preg_match( '/^(?:\{\{.+\}\}|\{[a-z0-9_.:-]+\})$/iD', trim( $value ) );
	}

	/**
	 * Parse a URL host without retaining the URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function parse_host( string $url ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}
		$parsed = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure parsing; no remote request.
		return is_array( $parsed ) && ! empty( $parsed['host'] ) ? (string) $parsed['host'] : '';
	}

	/**
	 * Normalize a host.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private function normalize_host( string $host ): string {
		$host = strtolower( rtrim( trim( $host ), '.' ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Match exact domain or a subdomain.
	 *
	 * @param string $host Host.
	 * @param string $domain Domain.
	 * @return bool
	 */
	private function matches_domain( string $host, string $domain ): bool {
		return $host === $domain || $this->ends_with( $host, '.' . $domain );
	}

	/**
	 * Treat same host/subdomain as internal.
	 *
	 * @param string $first Host.
	 * @param string $second Host.
	 * @return bool
	 */
	private function related_hosts( string $first, string $second ): bool {
		return $first === $second
			|| $this->ends_with( $first, '.' . $second )
			|| $this->ends_with( $second, '.' . $first );
	}

	/**
	 * Portable ends-with helper.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle Needle.
	 * @return bool
	 */
	private function ends_with( string $haystack, string $needle ): bool {
		$length = strlen( $needle );
		return 0 === $length || substr( $haystack, -$length ) === $needle;
	}
}
