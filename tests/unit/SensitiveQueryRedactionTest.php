<?php
/**
 * Sensitive query redaction for stored landing pages and referrers (issue #232).
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-event.php';

use CLICUTCL\Core\Attribution_Provider;
use CLICUTCL\Server_Side\Event;
use PHPUnit\Framework\TestCase;

/**
 * Class SensitiveQueryRedactionTest
 */
final class SensitiveQueryRedactionTest extends TestCase {

	private const BASE = 'https://shop.example.test';

	protected function setUp(): void {
		$GLOBALS['clicktrail_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['clicktrail_test_filters']       = array();
		$GLOBALS['clicktrail_test_strip_octets'] = false;
	}

	public function test_issue_scenario_removes_email_token_and_phone(): void {
		$url = self::BASE . '/pricing/?utm_source=mail&utm_medium=email&email=jane%40example.com&token=SECRET123&phone=5551234';

		$this->assertSame(
			self::BASE . '/pricing/?utm_source=mail&utm_medium=email',
			Attribution_Provider::redact_sensitive_query( $url )
		);
	}

	public function test_suffix_matches_but_lookalike_names_stay(): void {
		$url = self::BASE . '/p/?user_email=a%40b.co&my_key=K&key=wc_1&monkey=1&keyword=x&tokens=2&Api_Key=A';

		$this->assertSame(
			self::BASE . '/p/?monkey=1&keyword=x&tokens=2',
			Attribution_Provider::redact_sensitive_query( $url )
		);
	}

	public function test_encoded_names_and_plus_are_decoded_before_matching(): void {
		$this->assertSame(
			self::BASE . '/p/?page=2',
			Attribution_Provider::redact_sensitive_query( self::BASE . '/p/?%74oken=1&page=2&E%2Dmail=x' )
		);
	}

	public function test_utm_and_click_ids_are_kept_in_order_and_encoding(): void {
		$url = self::BASE . '/p/?gclid=G%201&utm_term=a%20b&email=x&fbclid=F1&gad_source=1&gad_campaignid=9&oppref=o&mc_cid=m&sc_click_id=s';

		$this->assertSame(
			self::BASE . '/p/?gclid=G%201&utm_term=a%20b&fbclid=F1&gad_source=1&gad_campaignid=9&oppref=o&mc_cid=m&sc_click_id=s',
			Attribution_Provider::redact_sensitive_query( $url )
		);
	}

	public function test_question_mark_and_fragment_are_dropped(): void {
		$this->assertSame( self::BASE . '/p/', Attribution_Provider::redact_sensitive_query( self::BASE . '/p/?email=x#access_token=T' ) );
		$this->assertSame( self::BASE . '/p/?gclid=G1', Attribution_Provider::redact_sensitive_query( self::BASE . '/p/?gclid=G1#frag' ) );
		$this->assertSame( self::BASE . '/p/', Attribution_Provider::redact_sensitive_query( self::BASE . '/p/' ) );
	}

	public function test_filter_replaces_the_list_but_never_removes_attribution_keys(): void {
		$GLOBALS['clicktrail_test_filters']['clicutcl_sensitive_query_keys'] = static function ( array $keys ): array {
			return array( ' Ref_No ', 'gclid', 'utm_source', 5, 'ref_no' );
		};

		$this->assertSame( array( 'ref_no', 'gclid', 'utm_source' ), Attribution_Provider::get_sensitive_query_keys() );
		$this->assertSame(
			self::BASE . '/p/?utm_source=a&email=x&gclid=G1',
			Attribution_Provider::redact_sensitive_query( self::BASE . '/p/?utm_source=a&email=x&gclid=G1&order_ref_no=9' )
		);
	}

	public function test_default_list_covers_the_documented_names(): void {
		$keys = Attribution_Provider::get_sensitive_query_keys();

		foreach ( array( 'email', 'e-mail', 'phone', 'token', 'access_token', 'password', 'key', 'api_key', 'code', 'session', 'sid', 'iban' ) as $name ) {
			$this->assertContains( $name, $keys );
		}
	}

	public function test_sanitize_redacts_landing_page_and_referrer_from_an_old_cookie(): void {
		// Core strips encoded octets in sanitize_text_field(); redaction must run first.
		$GLOBALS['clicktrail_test_strip_octets'] = true;

		$data = Attribution_Provider::sanitize(
			array(
				'ft_landing_page'    => self::BASE . '/pricing/?utm_source=mail&email=jane%40example.com&token=SECRET123',
				'lt_landing_page'    => self::BASE . '/pricing/?gclid=G1&phone=5551234',
				'ft_referrer'        => 'https://partner.example.org/r?id=7&session=S3',
				'lt_source'          => 'mail',
				'gclid'              => 'G1',
			)
		);

		$this->assertSame( self::BASE . '/pricing/?utm_source=mail', $data['ft_landing_page'] );
		$this->assertSame( self::BASE . '/pricing/?gclid=G1', $data['lt_landing_page'] );
		$this->assertSame( 'https://partner.example.org/r?id=7', $data['ft_referrer'] );
		$this->assertSame( 'mail', $data['lt_source'] );
		$this->assertSame( 'G1', $data['gclid'] );
		$this->assertStringNotContainsString( 'SECRET123', (string) json_encode( $data ) );
	}

	public function test_sanitize_redacts_the_first_landing_page_alias(): void {
		// Alone: when ft_landing_page is also present, sanitize() skips the alias.
		$GLOBALS['clicktrail_test_strip_octets'] = true;

		$data = Attribution_Provider::sanitize(
			array( 'first_landing_page' => self::BASE . '/legacy/?utm_campaign=c&password=P' )
		);

		$this->assertSame( self::BASE . '/legacy/?utm_campaign=c', $data['ft_landing_page'] );
		$this->assertStringNotContainsString( 'password', (string) json_encode( $data ) );
	}

	public function test_sanitize_leaves_other_fields_alone(): void {
		$data = Attribution_Provider::sanitize( array( 'lt_campaign' => 'code=spring', 'lt_term' => 'email?token=1' ) );

		$this->assertSame( 'code=spring', $data['lt_campaign'] );
		$this->assertSame( 'email?token=1', $data['lt_term'] );
	}

	public function test_event_redacts_the_page_referrer_and_the_trail_envelope(): void {
		$url   = self::BASE . '/pricing/?utm_source=mail&utm_medium=email&email=jane%40example.com&token=SECRET123&phone=5551234';
		$clean = self::BASE . '/pricing/?utm_source=mail&utm_medium=email';

		// Browser page_context only: the raw document.referrer of a second same-origin page.
		$event = Event::normalize(
			array(
				'event_name' => 'lead',
				'event_id'   => 'e1',
				'page'       => array( 'path' => '/quote/', 'referrer' => $url ),
			)
		);
		$this->assertSame( $clean, $event['page']['referrer'] );
		$this->assertSame( $clean, $event['marketing_trail']['referrer'] );

		// Values the browser supplied in the trail envelope, and a cookie written by an older version.
		$event = Event::normalize(
			array(
				'event_name'      => 'lead',
				'event_id'        => 'e2',
				'attribution'     => array( 'lt_landing_page' => $url, 'ft_referrer' => $url ),
				'marketing_trail' => array( 'landing_page' => $url, 'referrer' => $url ),
			)
		);
		$this->assertSame( $clean, $event['marketing_trail']['landing_page'] );
		$this->assertSame( $clean, $event['marketing_trail']['referrer'] );

		$event = Event::normalize(
			array(
				'event_name'  => 'lead',
				'event_id'    => 'e3',
				'attribution' => array( 'lt_landing_page' => $url, 'ft_referrer' => $url ),
			)
		);
		$this->assertSame( $clean, $event['marketing_trail']['landing_page'] );
		$this->assertSame( $clean, $event['marketing_trail']['referrer'] );
		$this->assertStringNotContainsString( 'SECRET123', (string) wp_json_encode( $event['marketing_trail'] ) );
	}
}
