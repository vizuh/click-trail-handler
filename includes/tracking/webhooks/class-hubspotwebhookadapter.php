<?php
/**
 * HubSpot webhook adapter.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking\Webhooks;

use CLICUTCL\Tracking\Event_Translator_V1_To_V2;
use CLICUTCL\Tracking\WebhookProviderAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HubSpotWebhookAdapter
 */
class HubSpotWebhookAdapter implements WebhookProviderAdapterInterface {
	/**
	 * Provider key.
	 *
	 * @return string
	 */
	public function get_provider_key(): string {
		return 'hubspot';
	}

	/**
	 * Check support.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	public function supports( array $payload ): bool {
		return ! empty( $payload['eventType'] ) || ! empty( $payload['subscriptionType'] );
	}

	/**
	 * Map payload to canonical event.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public function map_to_canonical( array $payload ): array {
		$event_type = sanitize_key( (string) ( $payload['eventType'] ?? $payload['subscriptionType'] ?? '' ) );
		$event_name = in_array( $event_type, array( 'contact.propertychange', 'contact_propertychange' ), true ) ? 'qualified_lead' : 'lead';

		$object_id = isset( $payload['objectId'] ) ? sanitize_text_field( (string) $payload['objectId'] ) : '';

		return Event_Translator_V1_To_V2::translate(
			array(
				'event_name'   => $event_name,
				'event_id'     => $object_id ? 'hs_' . md5( $event_type . '|' . $object_id ) : ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'hs_', true ) ),
				'source'       => 'webhook',
				'lead_context' => array(
					'provider'      => 'hubspot',
					'submit_status' => 'success',
				),
				'meta'         => array(
					'provider_event' => $event_type,
				),
			)
		);
	}
}

