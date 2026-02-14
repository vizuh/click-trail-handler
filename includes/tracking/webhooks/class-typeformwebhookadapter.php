<?php
/**
 * Typeform webhook adapter.
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
 * Class TypeformWebhookAdapter
 */
class TypeformWebhookAdapter implements WebhookProviderAdapterInterface {
	/**
	 * Provider key.
	 *
	 * @return string
	 */
	public function get_provider_key(): string {
		return 'typeform';
	}

	/**
	 * Check support.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	public function supports( array $payload ): bool {
		return ! empty( $payload['event_id'] ) || ! empty( $payload['form_response'] );
	}

	/**
	 * Map payload to canonical event.
	 *
	 * @param array $payload Raw payload.
	 * @return array
	 */
	public function map_to_canonical( array $payload ): array {
		$form_response = isset( $payload['form_response'] ) && is_array( $payload['form_response'] ) ? $payload['form_response'] : array();
		$token         = isset( $form_response['token'] ) ? sanitize_text_field( (string) $form_response['token'] ) : '';
		$form_id       = isset( $form_response['form_id'] ) ? sanitize_text_field( (string) $form_response['form_id'] ) : '';

		return Event_Translator_V1_To_V2::translate(
			array(
				'event_name'   => 'lead',
				'event_id'     => $token ? 'tf_' . md5( $token ) : ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'tf_', true ) ),
				'source'       => 'webhook',
				'lead_context' => array(
					'provider'      => 'typeform',
					'form_id'       => $form_id,
					'submit_status' => 'success',
				),
				'meta'         => array(
					'provider_event' => 'form_response',
				),
			)
		);
	}
}

