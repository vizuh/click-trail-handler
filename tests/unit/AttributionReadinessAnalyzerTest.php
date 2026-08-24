<?php
/**
 * Unit tests for the deterministic attribution readiness analyzer.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Intelligence/class-attribution-readiness-analyzer.php';

use CLICUTCL\Intelligence\Attribution_Readiness_Analyzer;
use PHPUnit\Framework\TestCase;

/**
 * Class AttributionReadinessAnalyzerTest
 */
final class AttributionReadinessAnalyzerTest extends TestCase {

	/**
	 * @var Attribution_Readiness_Analyzer
	 */
	private Attribution_Readiness_Analyzer $analyzer;

	protected function setUp(): void {
		$this->analyzer = new Attribution_Readiness_Analyzer();
	}

	public function test_complete_core_utm_payload_passes_without_recommendations(): void {
		$result = $this->analyzer->analyze(
			array(
				'utm_source'   => 'linkedin',
				'utm_medium'   => 'paid_social',
				'utm_campaign' => 'spring-launch',
			)
		);

		$this->assertSame( 'pass', $result['status'] );
		$this->assertSame( 'present', $result['fields']['utm_source']['status'] );
		$this->assertSame( 'present', $result['fields']['utm_medium']['status'] );
		$this->assertSame( 'present', $result['fields']['utm_campaign']['status'] );
		$this->assertSame( 'direct', $result['fields']['utm_source']['selection_tier'] );
		$this->assertSame( 'direct', $result['fields']['utm_medium']['selection_tier'] );
		$this->assertSame( 'direct', $result['fields']['utm_campaign']['selection_tier'] );
		$this->assertSame( array(), $result['issues'] );
		$this->assertSame( array(), $result['recommendations'] );
	}

	public function test_linkedin_click_id_deterministically_suggests_source_only(): void {
		$result = $this->analyzer->analyze( array( 'li_fat_id' => 'never-return-this-value' ) );

		$this->assertSame( 'attention', $result['status'] );
		$this->assertSame( 'linkedin', $result['source_evidence']['platform'] );
		$this->assertSame( 'click_id:li_fat_id', $result['source_evidence']['basis'] );
		$this->assertSame( 'missing_inferable', $result['fields']['utm_source']['status'] );
		$this->assertSame( 'linkedin', $result['fields']['utm_source']['suggested_value'] );
		$this->assertTrue( $result['fields']['utm_source']['deterministic'] );
		$this->assertSame( 'missing_unresolved', $result['fields']['utm_medium']['status'] );
		$this->assertSame( 'missing_unresolved', $result['fields']['utm_campaign']['status'] );
		$this->assertSame( 'add_utm_source', $result['recommendations'][0]['code'] );
		$this->assertTrue( $result['recommendations'][0]['deterministic'] );
		$this->assertStringNotContainsString( 'never-return-this-value', serialize( $result ) );
	}

	public function test_configured_source_alias_is_used_without_changing_deterministic_basis(): void {
		$result = $this->analyzer->analyze(
			array( 'li_fat_id' => 'opaque' ),
			'',
			'',
			array( 'source_aliases' => array( 'linkedin' => 'linkedin_ads' ) )
		);

		$this->assertSame( 'linkedin_ads', $result['recommendations'][0]['suggested_value'] );
		$this->assertSame( 'configured_alias', $result['recommendations'][0]['value_type'] );
		$this->assertSame( 'click_id:li_fat_id', $result['recommendations'][0]['basis'] );
	}

	public function test_click_id_does_not_invent_medium_or_campaign(): void {
		$result = $this->analyzer->analyze(
			array(
				'gclid'      => 'opaque',
				'utm_source' => 'google',
			)
		);

		$codes = array_column( $result['recommendations'], 'code' );
		$this->assertContains( 'configure_utm_medium', $codes );
		$this->assertContains( 'configure_utm_campaign', $codes );
		$this->assertNotContains( 'add_utm_medium', $codes );
		$this->assertNotContains( 'add_utm_campaign', $codes );
		$this->assertSame( 'missing_unresolved', $result['fields']['utm_medium']['status'] );
		$this->assertSame( 'missing_unresolved', $result['fields']['utm_campaign']['status'] );
	}

	public function test_source_conflict_is_reported_without_overwriting_observed_value(): void {
		$result = $this->analyzer->analyze(
			array(
				'li_fat_id'  => 'opaque',
				'utm_source' => 'google',
			),
			'',
			'',
			array( 'source_aliases' => array( 'linkedin' => 'linkedin_ads' ) )
		);

		$this->assertSame( 'google', $result['fields']['utm_source']['observed_value'] );
		$this->assertContains( 'source_signal_conflict', array_column( $result['issues'], 'code' ) );
		$this->assertNotContains( 'add_utm_source', array_column( $result['recommendations'], 'code' ) );
		$this->assertStringNotContainsString( 'linkedin_ads', serialize( $result ) );
		$this->assertSame( 'attention', $result['status'] );
	}

	public function test_referrer_is_evidence_but_not_deterministic_paid_source(): void {
		$result = $this->analyzer->analyze( array(), 'https://www.linkedin.com/feed/' );

		$this->assertSame( 'linkedin.com', $result['source_evidence']['referrer_host'] );
		$this->assertSame( 'missing_referrer_candidate', $result['fields']['utm_source']['status'] );
		$this->assertFalse( $result['fields']['utm_source']['deterministic'] );
		$this->assertSame( 'referrer_host', $result['fields']['utm_source']['suggestion_basis'] );
	}

	public function test_internal_referrer_does_not_create_source_candidate(): void {
		$result = $this->analyzer->analyze( array(), 'https://blog.example.com/post', 'example.com' );

		$this->assertSame( '', $result['source_evidence']['referrer_host'] );
		$this->assertSame( 'missing_unresolved', $result['fields']['utm_source']['status'] );
	}

	public function test_unresolved_macro_blocks_the_field(): void {
		$result = $this->analyzer->analyze(
			array(
				'utm_source'   => 'linkedin',
				'utm_medium'   => 'paid_social',
				'utm_campaign' => '{{campaign.name}}',
			)
		);

		$this->assertSame( 'blocked', $result['status'] );
		$this->assertSame( 'invalid_macro', $result['fields']['utm_campaign']['status'] );
		$this->assertSame( 'none', $result['fields']['utm_campaign']['selection_tier'] );
		$this->assertContains( 'unresolved_macro_utm_campaign', array_column( $result['issues'], 'code' ) );
	}

	public function test_unresolved_source_macro_blocks_click_id_alias_suggestion(): void {
		$result = $this->analyzer->analyze(
			array(
				'li_fat_id'  => 'opaque',
				'utm_source' => '{campaignid}',
			),
			'',
			'',
			array( 'source_aliases' => array( 'linkedin' => 'linkedin_ads' ) )
		);

		$this->assertSame( 'blocked', $result['status'] );
		$this->assertSame( 'invalid_macro', $result['fields']['utm_source']['status'] );
		$this->assertNotContains( 'add_utm_source', array_column( $result['recommendations'], 'code' ) );
		$this->assertStringNotContainsString( 'linkedin_ads', serialize( $result ) );
	}

	public function test_optional_fields_are_reported_without_missing_core_issues(): void {
		$result = $this->analyzer->analyze(
			array(
				'utm_source'   => 'google',
				'utm_medium'   => 'cpc',
				'utm_campaign' => 'brand',
			)
		);

		$this->assertSame( 'optional_missing', $result['fields']['utm_content']['status'] );
		$this->assertSame( 'optional_missing', $result['fields']['utm_term']['status'] );
		$this->assertSame( 'pass', $result['status'] );
	}

	public function test_flattened_last_touch_fields_are_supported(): void {
		$result = $this->analyzer->analyze(
			array(
				'lt_source'   => 'linkedin',
				'lt_medium'   => 'paid_social',
				'lt_campaign' => 'launch',
			)
		);

		$this->assertSame( 'present', $result['fields']['utm_source']['status'] );
		$this->assertSame( 'lt_source', $result['fields']['utm_source']['observed_key'] );
		$this->assertSame( 'present', $result['fields']['utm_medium']['status'] );
		$this->assertSame( 'present', $result['fields']['utm_campaign']['status'] );
	}

	public function test_multiple_platform_click_ids_are_flagged(): void {
		$result = $this->analyzer->analyze(
			array(
				'gclid'     => 'opaque-google',
				'li_fat_id' => 'opaque-linkedin',
			)
		);

		$this->assertSame( array( 'google', 'linkedin' ), $result['click_ids']['platforms'] );
		$this->assertContains( 'multiple_platform_signals', array_column( $result['issues'], 'code' ) );
	}

	public function test_field_policy_exposes_core_and_optional_contract(): void {
		$policy = Attribution_Readiness_Analyzer::field_policy();

		$this->assertSame( array( 'utm_source', 'utm_medium', 'utm_campaign' ), $policy['required'] );
		$this->assertContains( 'utm_id', $policy['optional'] );
		$this->assertContains( 'utm_marketing_tactic', $policy['optional'] );
	}

	public function test_source_alias_platforms_match_recognized_click_id_signals(): void {
		$platforms = Attribution_Readiness_Analyzer::source_alias_platforms();

		$this->assertContains( 'google', $platforms );
		$this->assertContains( 'linkedin', $platforms );
		$this->assertContains( 'display_video_360', $platforms );
		$this->assertNotContains( 'facebook', $platforms );
	}

	public function test_last_touch_takes_precedence_over_direct_and_first_touch(): void {
		$result = $this->analyzer->analyze(
			array(
				'ft_source'   => 'first-touch',
				'source'      => 'direct',
				'lt_source'   => 'last-touch',
				'lt_medium'   => 'paid_social',
				'lt_campaign' => 'launch',
			)
		);

		$this->assertSame( 'present', $result['fields']['utm_source']['status'] );
		$this->assertSame( 'lt_source', $result['fields']['utm_source']['observed_key'] );
		$this->assertSame( 'last-touch', $result['fields']['utm_source']['observed_value'] );
		$this->assertSame( 'last_touch', $result['fields']['utm_source']['selection_tier'] );
		$this->assertSame( 'present', $result['fields']['utm_medium']['status'] );
		$this->assertSame( 'present', $result['fields']['utm_campaign']['status'] );
	}

	public function test_direct_value_takes_precedence_over_first_touch(): void {
		$result = $this->analyzer->analyze(
			array(
				'ft_medium' => 'email',
				'medium'    => 'cpc',
				'campaign'  => 'brand',
			)
		);

		$this->assertSame( 'cpc', $result['fields']['utm_medium']['observed_value'] );
		$this->assertSame( 'medium', $result['fields']['utm_medium']['observed_key'] );
		$this->assertSame( 'brand', $result['fields']['utm_campaign']['observed_value'] );
	}

	public function test_first_touch_is_used_when_it_is_the_only_signal(): void {
		$result = $this->analyzer->analyze( array( 'ft_campaign' => 'launch' ) );

		$this->assertSame( 'present', $result['fields']['utm_campaign']['status'] );
		$this->assertSame( 'ft_campaign', $result['fields']['utm_campaign']['observed_key'] );
		$this->assertSame( 'first_touch', $result['fields']['utm_campaign']['selection_tier'] );
	}

	public function test_multiple_platforms_suppress_deterministic_suggestion(): void {
		$result = $this->analyzer->analyze(
			array(
				'gclid'     => 'opaque-google',
				'li_fat_id' => 'opaque-linkedin',
			),
			'',
			'',
			array(
				'source_aliases' => array(
					'google'   => 'google_ads',
					'linkedin' => 'linkedin_ads',
				),
			)
		);

		$this->assertSame( 'attention', $result['status'] );
		$this->assertSame( 'missing_unresolved', $result['fields']['utm_source']['status'] );
		$this->assertArrayNotHasKey( 'suggested_value', $result['fields']['utm_source'] );
		$this->assertArrayNotHasKey( 'deterministic', $result['fields']['utm_source'] );

		$recommendation_codes = array_column( $result['recommendations'], 'code' );
		$this->assertNotContains( 'add_utm_source', $recommendation_codes );
		$this->assertContains( 'configure_utm_medium', $recommendation_codes );
		$this->assertContains( 'configure_utm_campaign', $recommendation_codes );

		$issue_codes = array_column( $result['issues'], 'code' );
		$this->assertContains( 'multiple_platform_signals', $issue_codes );

		$serialized = serialize( $result );
		$this->assertStringNotContainsString( 'opaque-google', $serialized );
		$this->assertStringNotContainsString( 'opaque-linkedin', $serialized );
		$this->assertStringNotContainsString( 'google_ads', $serialized );
		$this->assertStringNotContainsString( 'linkedin_ads', $serialized );
	}

	public function test_same_platform_multiple_click_ids_remain_deterministic(): void {
		$result = $this->analyzer->analyze(
			array(
				'gclid'  => 'opaque-a',
				'wbraid' => 'opaque-b',
			)
		);

		$this->assertSame( array( 'google' ), $result['click_ids']['platforms'] );
		$this->assertSame( 'missing_inferable', $result['fields']['utm_source']['status'] );
		$this->assertSame( 'google', $result['fields']['utm_source']['suggested_value'] );
		$this->assertTrue( $result['fields']['utm_source']['deterministic'] );
	}

	public function test_test_url_builder_uses_only_observed_core_fields(): void {
		$analysis = $this->analyzer->analyze(
			array(
				'utm_source'   => 'newsletter',
				'utm_medium'   => 'paid social',
				'utm_campaign' => 'spring/launch',
				'utm_content'  => 'ignored-content',
			)
		);
		$url      = $this->analyzer->build_test_url( 'https://example.com/landing', $analysis );

		$this->assertSame(
			'https://example.com/landing?utm_source=newsletter&utm_medium=paid%20social&utm_campaign=spring%2Flaunch',
			$url
		);
		parse_str( (string) parse_url( (string) $url, PHP_URL_QUERY ), $query );
		$this->assertSame( array( 'utm_source', 'utm_medium', 'utm_campaign' ), array_keys( $query ) );
	}

	public function test_test_url_builder_uses_deterministic_alias_without_click_id_leakage(): void {
		$analysis = $this->analyzer->analyze(
			array( 'li_fat_id' => 'SECRET_CLICK_ID' ),
			'',
			'',
			array( 'source_aliases' => array( 'linkedin' => 'linkedin_ads' ) )
		);
		$url      = $this->analyzer->build_test_url( 'https://example.com/', $analysis );

		$this->assertSame( 'https://example.com/?utm_source=linkedin_ads', $url );
		$this->assertStringNotContainsString( 'SECRET_CLICK_ID', (string) $url );
		$this->assertStringNotContainsString( 'utm_medium', (string) $url );
		$this->assertStringNotContainsString( 'utm_campaign', (string) $url );
	}

	public function test_test_url_builder_preserves_observed_source_over_alias_suggestion(): void {
		$analysis = $this->analyzer->analyze(
			array(
				'li_fat_id'  => 'opaque',
				'utm_source' => 'partner',
			),
			'',
			'',
			array( 'source_aliases' => array( 'linkedin' => 'linkedin_ads' ) )
		);
		$url      = $this->analyzer->build_test_url( 'https://example.com/', $analysis );

		$this->assertSame( 'https://example.com/?utm_source=partner', $url );
		$this->assertStringNotContainsString( 'linkedin_ads', (string) $url );
	}

	public function test_test_url_builder_blocks_unresolved_or_invalid_core_fields(): void {
		$macro = $this->analyzer->analyze(
			array(
				'utm_source'   => 'google',
				'utm_medium'   => 'cpc',
				'utm_campaign' => '{campaignid}',
			)
		);
		$invalid = $this->analyzer->analyze( array( 'utm_source' => array( 'not-scalar' ) ) );

		$this->assertNull( $this->analyzer->build_test_url( 'https://example.com/', $macro ) );
		$this->assertNull( $this->analyzer->build_test_url( 'https://example.com/', $invalid ) );
		$this->assertNull( $this->analyzer->build_test_url( 'https://example.com/', $this->analyzer->analyze( array() ) ) );
	}

	public function test_test_url_builder_rejects_unsafe_or_ambiguous_base_urls(): void {
		$analysis = $this->analyzer->analyze( array( 'utm_source' => 'test' ) );
		$bases    = array(
			'javascript://example.com/',
			'data://example.com/',
			'/relative/path',
			'https://user@example.com/',
			'https://example.com:443/',
			'https://example.com/?existing=1',
			'https://example.com/#fragment',
			'https://example.com\\@evil.test/',
			' https://example.com/',
		);

		foreach ( $bases as $base ) {
			$this->assertNull( $this->analyzer->build_test_url( $base, $analysis ), $base );
		}
	}

	public function test_test_url_builder_enforces_total_output_bound(): void {
		$analysis = $this->analyzer->analyze( array( 'utm_source' => str_repeat( 'a', 255 ) ) );
		$base     = 'https://example.com/' . str_repeat( 'p', 2020 );

		$this->assertLessThanOrEqual( 2048, strlen( $base ) );
		$this->assertNull( $this->analyzer->build_test_url( $base, $analysis ) );
	}
}
