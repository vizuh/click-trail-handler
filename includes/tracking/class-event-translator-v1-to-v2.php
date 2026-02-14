<?php
/**
 * Event translator: legacy payloads -> canonical event v2.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

use CLICUTCL\Server_Side\Consent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Event_Translator_V1_To_V2
 */
class Event_Translator_V1_To_V2 {
	/**
	 * Translate a legacy payload to canonical v2 format.
	 *
	 * @param array $input Legacy or canonical-like input.
	 * @return array
	 */
	public static function translate( array $input ): array {
		$event_name = '';
		if ( ! empty( $input['event_name'] ) ) {
			$event_name = sanitize_key( (string) $input['event_name'] );
		} elseif ( ! empty( $input['event'] ) ) {
			$event_name = sanitize_key( (string) $input['event'] );
		}

		$event_id = isset( $input['event_id'] ) ? sanitize_text_field( (string) $input['event_id'] ) : '';
		if ( '' === $event_id && function_exists( 'wp_generate_uuid4' ) ) {
			$event_id = wp_generate_uuid4();
		}

		$event_time = isset( $input['event_time'] ) ? absint( $input['event_time'] ) : 0;
		if ( ! $event_time ) {
			$event_time = isset( $input['timestamp'] ) ? absint( $input['timestamp'] ) : 0;
		}
		if ( ! $event_time ) {
			$event_time = isset( $input['ts'] ) ? absint( $input['ts'] ) : 0;
		}
		if ( ! $event_time ) {
			$event_time = time();
		}

		$page_context = array();
		if ( isset( $input['page_context'] ) && is_array( $input['page_context'] ) ) {
			$page_context = $input['page_context'];
		} elseif ( isset( $input['page'] ) && is_array( $input['page'] ) ) {
			$page_context = $input['page'];
		} elseif ( ! empty( $input['page_path'] ) ) {
			$page_context = array( 'path' => sanitize_text_field( (string) $input['page_path'] ) );
		}

		$consent = array();
		if ( isset( $input['consent'] ) && is_array( $input['consent'] ) ) {
			$consent = $input['consent'];
		} else {
			$consent = Consent::get_state();
		}

		$funnel_stage = self::infer_funnel_stage( $event_name );
		$intent_level = self::infer_intent_level( $funnel_stage, $event_name );
		$source       = isset( $input['source_channel'] ) ? sanitize_key( (string) $input['source_channel'] ) : '';
		if ( '' === $source ) {
			$source = isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : 'web';
		}

		$session_id = isset( $input['session_id'] ) ? sanitize_text_field( (string) $input['session_id'] ) : '';
		if ( '' === $session_id && isset( $_COOKIE['ct_session_id'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			$session_id = sanitize_text_field( wp_unslash( $_COOKIE['ct_session_id'] ) );
		}

		$canonical = array(
			'event_name'      => $event_name,
			'event_id'        => $event_id,
			'event_time'      => $event_time,
			'funnel_stage'    => $funnel_stage,
			'session_id'      => $session_id,
			'source_channel'  => $source,
			'page_context'    => $page_context,
			'attribution'     => isset( $input['attribution'] ) && is_array( $input['attribution'] ) ? $input['attribution'] : array(),
			'consent'         => $consent,
			'lead_context'    => isset( $input['lead_context'] ) && is_array( $input['lead_context'] ) ? $input['lead_context'] : ( isset( $input['form'] ) && is_array( $input['form'] ) ? $input['form'] : array() ),
			'commerce_context'=> isset( $input['commerce_context'] ) && is_array( $input['commerce_context'] ) ? $input['commerce_context'] : ( isset( $input['commerce'] ) && is_array( $input['commerce'] ) ? $input['commerce'] : array() ),
			'identity'        => isset( $input['identity'] ) && is_array( $input['identity'] ) ? $input['identity'] : array(),
			'delivery_context'=> isset( $input['delivery_context'] ) && is_array( $input['delivery_context'] ) ? $input['delivery_context'] : array(),
			'meta'            => isset( $input['meta'] ) && is_array( $input['meta'] ) ? $input['meta'] : array(),
		);

		$canonical['meta']['schema_version'] = 2;
		if ( empty( $input['event_time'] ) ) {
			$canonical['meta']['migrated_from_v1'] = true;
		}
		$canonical['meta']['segments'] = array(
			'intent_level' => $intent_level,
		);

		return EventV2::normalize( $canonical );
	}

	/**
	 * Infer funnel stage from event name.
	 *
	 * @param string $event_name Event name.
	 * @return string
	 */
	private static function infer_funnel_stage( string $event_name ): string {
		$event_name = sanitize_key( $event_name );

		$top = array( 'page_view', 'key_page_view', 'view_content', 'search', 'video_view', 'scroll_depth' );
		$mid = array( 'cta_click', 'form_start', 'form_submit_attempt', 'contact_call_click', 'contact_whatsapp_start', 'contact_chat_start' );
		$bot = array( 'lead', 'book_appointment', 'qualified_lead', 'client_won', 'purchase' );

		if ( in_array( $event_name, $top, true ) ) {
			return 'top';
		}
		if ( in_array( $event_name, $mid, true ) ) {
			return 'mid';
		}
		if ( in_array( $event_name, $bot, true ) ) {
			return 'bottom';
		}

		return 'unknown';
	}

	/**
	 * Infer intent level segment from funnel stage/event.
	 *
	 * @param string $funnel_stage Funnel stage.
	 * @param string $event_name   Event name.
	 * @return string
	 */
	private static function infer_intent_level( string $funnel_stage, string $event_name ): string {
		$event_name = sanitize_key( $event_name );
		$funnel_stage = sanitize_key( $funnel_stage );

		if ( in_array( $event_name, array( 'lead', 'book_appointment', 'qualified_lead', 'client_won', 'purchase' ), true ) ) {
			return 'converted';
		}
		if ( in_array( $event_name, array( 'cta_click', 'form_start', 'form_submit_attempt', 'contact_call_click', 'contact_whatsapp_start', 'contact_chat_start' ), true ) ) {
			return 'high';
		}
		if ( 'mid' === $funnel_stage ) {
			return 'mid';
		}
		if ( 'top' === $funnel_stage ) {
			return 'low';
		}

		return 'low';
	}
}
