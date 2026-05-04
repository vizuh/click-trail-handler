<?php
/**
 * Unit tests for Gf_Channel_Resolver.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-gf-channel-resolver.php';

use CLICUTCL\Integrations\Forms\Gf_Channel_Resolver;
use PHPUnit\Framework\TestCase;

/**
 * Class ChannelResolverTest
 *
 * Locks in the classification rules that we'd otherwise only catch breaking
 * via reports going wrong weeks later. If you change a rule in the resolver,
 * update the JS counterpart and adjust the corresponding test below.
 */
final class ChannelResolverTest extends TestCase {

	/**
	 * @var Gf_Channel_Resolver
	 */
	private Gf_Channel_Resolver $resolver;

	protected function setUp(): void {
		$this->resolver = new Gf_Channel_Resolver();
	}

	/** Case 1: Google paid click ID classifies as Google Ads. */
	public function test_gclid_classifies_as_google_ads(): void {
		$this->assertSame(
			'Google Ads',
			$this->resolver->resolve( array( 'gclid' => 'abc123' ) )
		);
	}

	/** Case 2: fbclid + paid medium classifies as Facebook Ads. */
	public function test_fbclid_with_cpc_medium_classifies_as_facebook_ads(): void {
		$this->assertSame(
			'Facebook Ads',
			$this->resolver->resolve(
				array(
					'fbclid'     => 'fbabc',
					'utm_medium' => 'cpc',
				)
			)
		);
	}

	/** Case 3: fbclid alone (no paid medium) is NOT Facebook Ads. */
	public function test_fbclid_without_paid_medium_is_not_facebook_ads(): void {
		$result = $this->resolver->resolve( array( 'fbclid' => 'fbabc' ) );
		$this->assertNotSame( 'Facebook Ads', $result );
		// It should fall through to Facebook Organic.
		$this->assertSame( 'Facebook Organic', $result );
	}

	/** Case 4: Microsoft click ID classifies as Microsoft Ads. */
	public function test_msclkid_classifies_as_microsoft_ads(): void {
		$this->assertSame(
			'Microsoft Ads',
			$this->resolver->resolve( array( 'msclkid' => 'msabc' ) )
		);
	}

	/** Case 5: gemini.google.com referrer classifies as Gemini, NOT Google Organic. */
	public function test_gemini_referrer_classifies_as_gemini(): void {
		$result = $this->resolver->resolve( array(), 'https://gemini.google.com/app' );
		$this->assertSame( 'Gemini', $result );
		$this->assertNotSame( 'Google Organic', $result );
	}

	/** Case 6: google.com search referrer classifies as Google Organic. */
	public function test_google_search_referrer_classifies_as_google_organic(): void {
		$this->assertSame(
			'Google Organic',
			$this->resolver->resolve( array(), 'https://www.google.com/search?q=clicktrail' )
		);
	}

	/** Case 7: chatgpt.com referrer classifies as ChatGPT. */
	public function test_chatgpt_referrer_classifies_as_chatgpt(): void {
		$this->assertSame(
			'ChatGPT',
			$this->resolver->resolve( array(), 'https://chatgpt.com/c/abc' )
		);
	}

	/** Case 8: Click ID wins over organic referrer. */
	public function test_click_id_wins_over_organic_referrer(): void {
		$this->assertSame(
			'Google Ads',
			$this->resolver->resolve(
				array( 'gclid' => 'abc' ),
				'https://www.google.com/search?q=anything'
			)
		);
	}

	/** Case 9: No source signals at all → Unknown (per Q1=A, no behavior change). */
	public function test_no_signals_returns_unknown(): void {
		$this->assertSame( 'Unknown', $this->resolver->resolve( array() ) );
	}

	/** Case 10: Internal referrer is treated as direct/no signal → Unknown. */
	public function test_internal_referrer_returns_unknown(): void {
		$this->assertSame(
			'Unknown',
			$this->resolver->resolve(
				array(),
				'https://example.com/blog/post',
				'example.com'
			)
		);
	}
}
