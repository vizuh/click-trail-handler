<?php
/**
 * Calendly webhook adapter.
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
 * Class CalendlyWebhookAdapter
 */
class CalendlyWebhookAdapter implements WebhookProviderAdapterInterface {
	/**
	 * Provider key.
	 *
	 * @return string
	 */
	public function get_provider_key(): string {
		return 'calendly';
	}

	/**
	 * Check payload support.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	public function supports( array $payload ): bool {
		return ! empty( $payload['event'] );
	}

	/**
	 * Map Calendly payload to canonical event.
	 *
	 * @param array $payload Raw payload.
	 * @return array
	 */
	public function map_to_canonical( array $payload ): array {
		$event      = sanitize_key( (string) ( $payload['event'] ?? '' ) );
		$booked     = in_array( $event, array( 'invitee_created', 'invitee.created' ), true );
		$lead_stage = $booked ? 'book_appointment' : 'lead';

		$resource = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();
		$uri      = isset( $resource['uri'] ) ? sanitize_text_field( (string) $resource['uri'] ) : '';
		$email    = isset( $resource['email'] ) ? sanitize_email( (string) $resource['email'] ) : '';

		return Event_Translator_V1_To_V2::translate(
			array(
				'event_name'   => $lead_stage,
				'event_id'     => $uri ? 'cal_' . md5( $uri ) : ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cal_', true ) ),
				'source'       => 'webhook',
				'lead_context' => array(
					'provider'      => 'calendly',
					'submit_status' => 'success',
				),
				'identity'     => array(
					'email' => $email,
				),
				'meta'         => array(
					'provider_event' => $event,
				),
			)
		);
	}
}

