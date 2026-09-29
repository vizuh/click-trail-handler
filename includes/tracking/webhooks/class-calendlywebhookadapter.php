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
	 * Only a created booking is a conversion; cancellations and other Calendly
	 * events are acknowledged without recording an event.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	public function is_ignored_event( array $payload ): bool {
		return ! empty( $payload['event'] ) && ! in_array( self::event_name( $payload ), array( 'invitee.created', 'invitee_created' ), true );
	}

	/**
	 * Parse ClickTrail's Calendly stamp from tracking.salesforce_uuid:
	 * "ct1[;<click_id_key>=<value>][;c=<0|1>]" with at least one segment. c is absent
	 * when the visitor had no consent decision. Any other value is the host's own
	 * Salesforce ID and is ignored.
	 *
	 * @param string $value Raw salesforce_uuid.
	 * @return array{click_ids:array<string,string>,consent:?bool}|null
	 */
	private static function parse_stamp( string $value ): ?array {
		$keys = 'gclid|gbraid|wbraid|fbclid|msclkid|ttclid|li_fat_id|twclid|dclid';
		if ( 'ct1' === $value || ! preg_match( '/^ct1(?:;(' . $keys . ')=([A-Za-z0-9._-]{1,200}))?(?:;c=([01]))?$/', $value, $match ) ) {
			return null;
		}

		return array(
			'click_ids' => ! empty( $match[1] ) ? array( $match[1] => $match[2] ) : array(),
			'consent'   => isset( $match[3] ) && '' !== $match[3] ? '1' === $match[3] : null,
		);
	}

	/**
	 * Normalized provider event name. Calendly names contain a dot ("invitee.created"),
	 * which sanitize_key() would strip.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private static function event_name( array $payload ): string {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $payload['event'] ?? '' ) ) );
	}

	/**
	 * Map Calendly payload to canonical event.
	 *
	 * @param array $payload Raw payload.
	 * @return array
	 */
	public function map_to_canonical( array $payload ): array {
		$event      = self::event_name( $payload );
		$booked     = in_array( $event, array( 'invitee_created', 'invitee.created' ), true );
		$lead_stage = $booked ? 'book_appointment' : 'lead';

		$resource = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();
		$uri      = isset( $resource['uri'] ) ? sanitize_text_field( (string) $resource['uri'] ) : '';
		$email    = isset( $resource['email'] ) ? sanitize_email( (string) $resource['email'] ) : '';

		// Calendly keeps only these UTMs from the scheduling URL (payload.tracking).
		// They describe the booking visit, so they map to last touch.
		$tracking    = isset( $resource['tracking'] ) && is_array( $resource['tracking'] ) ? $resource['tracking'] : array();
		$attribution = array();
		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $field ) {
			$value = isset( $tracking[ 'utm_' . $field ] ) && is_scalar( $tracking[ 'utm_' . $field ] ) ? sanitize_text_field( (string) $tracking[ 'utm_' . $field ] ) : '';
			if ( '' !== $value ) {
				$attribution[ 'lt_' . $field ] = mb_substr( $value, 0, 255 );
			}
		}

		$input = array();
		$stamp = self::parse_stamp( isset( $tracking['salesforce_uuid'] ) && is_scalar( $tracking['salesforce_uuid'] ) ? (string) $tracking['salesforce_uuid'] : '' );
		if ( null !== $stamp ) {
			$attribution = array_merge( $attribution, $stamp['click_ids'] );
			// The visitor's consent decision when the booking link was decorated; same trust
			// level as the consent cookie it was read from. A webhook has no cookie of its own.
			if ( null !== $stamp['consent'] ) {
				$input['consent'] = array( 'marketing' => $stamp['consent'] );
			}
		}

		return Event_Translator_V1_To_V2::translate(
			$input + array(
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
				'attribution'  => $attribution,
				'meta'         => array(
					'provider_event' => $event,
				),
			)
		);
	}
}
