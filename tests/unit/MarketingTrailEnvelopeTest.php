<?php
/**
 * Tests the normalized marketing trail envelope added to legacy events.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-event.php';

use CLICUTCL\Server_Side\Event;
use PHPUnit\Framework\TestCase;

/**
 * Class MarketingTrailEnvelopeTest
 */
final class MarketingTrailEnvelopeTest extends TestCase {
	public function test_form_event_emits_a_normalized_envelope_from_flat_attribution(): void {
		$event = Event::normalize(
			array(
				'event_name'  => 'form_submission',
				'event_id'    => 'form_123',
				'timestamp'   => 1_700_000_000,
				'source'      => 'server',
				'form'        => array( 'platform' => 'elementor', 'id' => 'consultation' ),
				'attribution' => array(
					'ft_source'       => 'google',
					'ft_medium'       => 'cpc',
					'ft_campaign'     => 'botox_new_york',
					'ft_landing_page' => '/botox-consultation',
					'ft_referrer'     => 'https://google.com/',
					'gclid'           => 'gclid-1',
					'visitor_id'      => 'visitor-1',
				),
				'consent'     => array( 'marketing' => true, 'analytics' => true ),
				'meta'        => array( 'site_id' => 1 ),
			)
		);

		$this->assertSame(
			array(
				'schema_version' => 1,
				'event_id'       => 'evt_form_123',
				'trail_id'       => 'trl_visitor-1',
				'anonymous_id'   => 'anon_visitor-1',
				'lead_id'        => 'lead_form_123',
				'workspace_id'   => '',
				'site_id'        => '1',
				'event_name'     => 'lead_submitted',
				'occurred_at'    => '2023-11-14T22:13:20Z',
				'landing_page'   => '/botox-consultation',
				'referrer'       => 'https://google.com/',
				'source'         => 'google',
				'medium'         => 'cpc',
				'campaign'       => 'botox_new_york',
				'click_ids'      => array( 'gclid' => 'gclid-1' ),
				'consent'        => array( 'analytics' => true, 'advertising' => true ),
				'form'           => array( 'provider' => 'elementor', 'form_id' => 'consultation' ),
			),
			$event['marketing_trail']
		);
	}
}
