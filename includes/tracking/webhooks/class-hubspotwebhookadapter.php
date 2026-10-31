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
	 * Check support: the subscription type is on the allowlist.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	public function supports( array $payload ): bool {
		return in_array( str_replace( '_', '.', self::event_type( $payload ) ), self::allowed_event_types(), true );
	}

	/**
	 * Subscription types off the allowlist (deletion, merge, restore, associationChange,
	 * privacyDeletion, unknown) and contact property changes that do not meet the
	 * qualified-lead rule are not conversions; they are acknowledged without recording an
	 * event, so HubSpot does not retry them.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	public function is_ignored_event( array $payload ): bool {
		return ! $this->supports( $payload )
			|| ( self::is_property_change( $payload ) && ! self::is_qualifying_change( $payload ) );
	}

	/**
	 * Normalized allowlist of recorded subscription types.
	 *
	 * @return string[]
	 */
	private static function allowed_event_types(): array {
		/**
		 * Filter the HubSpot subscription types ClickTrail records. contact.propertyChange stays
		 * gated by clicutcl_hubspot_qualified_lead_rule and maps to qualified_lead; every other
		 * listed type maps to lead. Unlisted types are acknowledged with 2xx and ignored.
		 *
		 * @param string[] $types Subscription types, e.g. "contact.creation".
		 */
		$types = apply_filters( 'clicutcl_hubspot_webhook_event_types', array( 'contact.creation', 'contact.propertyChange' ) );
		$types = is_array( $types ) ? $types : array();

		return array_values(
			array_filter(
				array_map(
					static fn( $type ) => is_scalar( $type ) ? str_replace( '_', '.', strtolower( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $type ) ) ) : '',
					$types
				),
				'strlen'
			)
		);
	}

	/**
	 * Normalized subscription type. HubSpot names contain a dot ("contact.propertyChange"),
	 * which sanitize_key() would strip.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private static function event_type( array $payload ): string {
		$raw = '' !== self::text( $payload, 'eventType' ) ? self::text( $payload, 'eventType' ) : self::text( $payload, 'subscriptionType' );
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $raw ) );
	}

	/**
	 * Scalar payload value as a string; arrays and objects read as empty.
	 *
	 * @param array  $payload Payload.
	 * @param string $key     Key.
	 * @return string
	 */
	private static function text( array $payload, string $key ): string {
		return isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ? (string) $payload[ $key ] : '';
	}

	/**
	 * Whether the payload is a contact property change.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	private static function is_property_change( array $payload ): bool {
		return in_array( self::event_type( $payload ), array( 'contact.propertychange', 'contact_propertychange' ), true );
	}

	/**
	 * Whether a property change moves the contact into a qualified lifecycle stage.
	 * Default: lifecyclestage set to marketingqualifiedlead or salesqualifiedlead.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	private static function is_qualifying_change( array $payload ): bool {
		if ( ! self::is_property_change( $payload ) ) {
			return false;
		}

		/**
		 * Filter the HubSpot property and values that mark a qualified lead.
		 *
		 * @param array $rule { property: string, values: string[] }.
		 */
		$rule = apply_filters(
			'clicutcl_hubspot_qualified_lead_rule',
			array(
				'property' => 'lifecyclestage',
				'values'   => array( 'marketingqualifiedlead', 'salesqualifiedlead' ),
			)
		);

		$property = is_array( $rule ) && isset( $rule['property'] ) && is_scalar( $rule['property'] ) ? strtolower( (string) $rule['property'] ) : '';
		$values   = is_array( $rule ) && isset( $rule['values'] ) && is_array( $rule['values'] ) ? $rule['values'] : array();
		$values   = array_filter(
			array_map(
				static fn( $value ) => is_scalar( $value ) ? strtolower( (string) $value ) : '',
				$values
			),
			'strlen'
		);
		$value    = strtolower( self::text( $payload, 'propertyValue' ) );

		return '' !== $property
			&& '' !== $value
			&& strtolower( self::text( $payload, 'propertyName' ) ) === $property
			&& in_array( $value, $values, true );
	}

	/**
	 * Map payload to canonical event.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public function map_to_canonical( array $payload ): array {
		$event_type = self::event_type( $payload );
		$qualified  = self::is_qualifying_change( $payload );
		$event_name = $qualified ? 'qualified_lead' : 'lead';

		$object_id = sanitize_text_field( self::text( $payload, 'objectId' ) );
		// Non-qualified events keep the pre-#107 ID basis so retries already in flight still dedupe.
		// A qualified event adds the stage and HubSpot's own eventId: MQL then SQL, or a later
		// re-entry into a stage, are separate events, while a retry of the same delivery is not.
		$id_basis = $qualified
			? $event_type . '|' . $object_id . '|' . strtolower( self::text( $payload, 'propertyValue' ) ) . '|' . self::text( $payload, 'eventId' )
			: sanitize_key( $event_type ) . '|' . $object_id;

		return Event_Translator_V1_To_V2::translate(
			array(
				'event_name'   => $event_name,
				'event_id'     => $object_id ? 'hs_' . md5( $id_basis ) : ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'hs_', true ) ),
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
