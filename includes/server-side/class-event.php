<?php
/**
 * Canonical Event
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Event
 */
class Event {
	/**
	 * Schema version.
	 */
	const VERSION = 1;

	/**
	 * Event data.
	 *
	 * @var array
	 */
	private $data = array();

	/**
	 * Constructor.
	 *
	 * @param array $data Event data.
	 */
	public function __construct( $data ) {
		$data = is_array( $data ) ? $data : array();
		$normalized = self::normalize( $data );

		if ( ! self::validate( $normalized ) ) {
			$normalized = array(
				'event_name' => 'invalid_event',
				'event_id'   => '',
				'timestamp'  => time(),
				'source'     => 'server',
				'meta'       => array(
					'schema_version' => self::VERSION,
				),
			);
		}

		$this->data = $normalized;
	}

	/**
	 * Return event array.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Normalize event data to schema.
	 *
	 * @param array $data Event data.
	 * @return array
	 */
	public static function normalize( $data ) {
		$event = array(
			'event_name' => isset( $data['event_name'] ) ? sanitize_text_field( (string) $data['event_name'] ) : '',
			'event_id'   => isset( $data['event_id'] ) ? sanitize_text_field( (string) $data['event_id'] ) : '',
			'timestamp'  => isset( $data['timestamp'] ) ? absint( $data['timestamp'] ) : time(),
			'source'     => isset( $data['source'] ) ? sanitize_text_field( (string) $data['source'] ) : 'server',
		);

		$event['page']        = isset( $data['page'] ) && is_array( $data['page'] ) ? $data['page'] : array();
		$event['wa']          = isset( $data['wa'] ) && is_array( $data['wa'] ) ? $data['wa'] : array();
		$event['form']        = isset( $data['form'] ) && is_array( $data['form'] ) ? $data['form'] : array();
		$event['commerce']    = isset( $data['commerce'] ) && is_array( $data['commerce'] ) ? $data['commerce'] : array();
		$event['attribution'] = isset( $data['attribution'] ) && is_array( $data['attribution'] ) ? $data['attribution'] : array();
		$event['consent']     = isset( $data['consent'] ) && is_array( $data['consent'] ) ? $data['consent'] : array();
		$event['meta']        = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		$event['meta']['schema_version'] = self::VERSION;

		return $event;
	}

	/**
	 * Schema definition (required + optional keys).
	 *
	 * @return array
	 */
	public static function schema() {
		return array(
			'required' => array( 'event_name', 'event_id', 'timestamp', 'source' ),
			'optional' => array( 'page', 'wa', 'form', 'commerce', 'attribution', 'consent', 'meta' ),
			'version'  => self::VERSION,
		);
	}

	/**
	 * Validate event schema.
	 *
	 * @param array $data Event data.
	 * @return bool
	 */
	public static function validate( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}

		$required = array( 'event_name', 'event_id', 'timestamp', 'source' );
		foreach ( $required as $key ) {
			if ( empty( $data[ $key ] ) ) {
				return false;
			}
		}

		if ( ! is_int( $data['timestamp'] ) && ! is_numeric( $data['timestamp'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build event from WA click payload.
	 *
	 * @param array $payload Payload.
	 * @return Event
	 */
	public static function from_wa_click( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();

		$data = array(
			'event_name' => 'wa_click',
			'event_id'   => $payload['event_id'] ?? '',
			'timestamp'  => isset( $payload['ts'] ) ? absint( $payload['ts'] ) : time(),
			'source'     => 'web',
			'page'       => array(
				'path' => $payload['page_path'] ?? '',
			),
			'wa'         => array(
				'target_type' => $payload['wa_target_type'] ?? '',
				'target_path' => $payload['wa_target_path'] ?? '',
			),
			'attribution' => isset( $payload['attribution'] ) && is_array( $payload['attribution'] ) ? $payload['attribution'] : array(),
			'meta'        => array(
				'site_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				'plugin_version' => defined( 'CLICUTCL_VERSION' ) ? CLICUTCL_VERSION : '',
			),
		);

		$consent = Consent::get_state();
		if ( ! empty( $consent ) ) {
			$data['consent'] = $consent;
		}

		return new self( $data );
	}

	/**
	 * Build event from form submission.
	 *
	 * @param string $platform Platform name.
	 * @param mixed  $form_id Form ID.
	 * @param array  $attribution Attribution payload.
	 * @param array  $context Optional context (event_id, timestamp, page_path).
	 * @return Event
	 */
	public static function from_form_submission( $platform, $form_id, $attribution, $context = array() ) {
		$context  = is_array( $context ) ? $context : array();
		$platform = sanitize_text_field( (string) $platform );
		$form_id  = is_scalar( $form_id ) ? sanitize_text_field( (string) $form_id ) : '';

		$event_id  = isset( $context['event_id'] ) ? sanitize_text_field( (string) $context['event_id'] ) : self::generate_id( 'form' );
		$timestamp = isset( $context['timestamp'] ) ? absint( $context['timestamp'] ) : time();
		$page_path = self::detect_page_path( $context );

		$data = array(
			'event_name' => 'form_submission',
			'event_id'   => $event_id,
			'timestamp'  => $timestamp,
			'source'     => 'server',
			'form'       => array(
				'platform' => $platform,
				'id'       => $form_id,
			),
			'attribution' => is_array( $attribution ) ? $attribution : array(),
			'meta'        => array(
				'site_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				'plugin_version' => defined( 'CLICUTCL_VERSION' ) ? CLICUTCL_VERSION : '',
			),
		);

		if ( $page_path ) {
			$data['page'] = array(
				'path' => $page_path,
			);
		}

		$consent = Consent::get_state();
		if ( ! empty( $consent ) ) {
			$data['consent'] = $consent;
		}

		return new self( $data );
	}

	/**
	 * Build event from purchase payload.
	 *
	 * @param array $payload Purchase data.
	 * @return Event
	 */
	public static function from_purchase( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();

		$order_id       = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
		$transaction_id = isset( $payload['transaction_id'] ) ? sanitize_text_field( (string) $payload['transaction_id'] ) : '';
		$event_id       = isset( $payload['event_id'] ) ? sanitize_text_field( (string) $payload['event_id'] ) : '';
		$event_id       = $event_id ? $event_id : ( $transaction_id ? 'purchase_' . $transaction_id : ( $order_id ? 'purchase_' . $order_id : self::generate_id( 'purchase' ) ) );
		$timestamp      = isset( $payload['timestamp'] ) ? absint( $payload['timestamp'] ) : time();

		$items = array();
		if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
			foreach ( $payload['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$items[] = array(
					'item_id'   => isset( $item['item_id'] ) ? sanitize_text_field( (string) $item['item_id'] ) : '',
					'item_name' => isset( $item['item_name'] ) ? sanitize_text_field( (string) $item['item_name'] ) : '',
					'price'     => isset( $item['price'] ) ? (float) $item['price'] : 0.0,
					'quantity'  => isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0,
				);
			}
		}

		$data = array(
			'event_name' => 'purchase',
			'event_id'   => $event_id,
			'timestamp'  => $timestamp,
			'source'     => 'server',
			'commerce'   => array(
				'transaction_id' => $transaction_id,
				'value'          => isset( $payload['value'] ) ? (float) $payload['value'] : 0.0,
				'currency'       => isset( $payload['currency'] ) ? sanitize_text_field( (string) $payload['currency'] ) : '',
				'items'          => $items,
			),
			'attribution' => isset( $payload['attribution'] ) && is_array( $payload['attribution'] ) ? $payload['attribution'] : array(),
			'meta'        => array(
				'order_id'       => $order_id,
				'site_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				'plugin_version' => defined( 'CLICUTCL_VERSION' ) ? CLICUTCL_VERSION : '',
			),
		);

		$page_path = self::detect_page_path( $payload );
		if ( $page_path ) {
			$data['page'] = array(
				'path' => $page_path,
			);
		}

		$consent = Consent::get_state();
		if ( ! empty( $consent ) ) {
			$data['consent'] = $consent;
		}

		return new self( $data );
	}

	/**
	 * Generate an event ID.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	public static function generate_id( $prefix = 'ct' ) {
		$prefix = sanitize_key( (string) $prefix );

		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$uuid = wp_generate_uuid4();
		} else {
			try {
				$uuid = bin2hex( random_bytes( 16 ) );
			} catch ( \Exception $e ) {
				$uuid = uniqid( 'ct_', true );
			}
		}

		return $prefix ? $prefix . '_' . $uuid : $uuid;
	}

	/**
	 * Detect page path from context or request.
	 *
	 * @param array $context Optional context.
	 * @return string
	 */
	private static function detect_page_path( $context = array() ) {
		$context = is_array( $context ) ? $context : array();

		if ( ! empty( $context['page_path'] ) ) {
			return self::sanitize_path( $context['page_path'] );
		}

		$referer = wp_get_referer();
		if ( $referer ) {
			$path = self::sanitize_path( $referer );
			if ( $path ) {
				return $path;
			}
		}

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );
			return self::sanitize_path( $request_uri );
		}

		return '';
	}

	/**
	 * Sanitize a URL or path into a safe path-only string.
	 *
	 * @param string $url_or_path URL or path.
	 * @return string
	 */
	public static function sanitize_path( $url_or_path ) {
		if ( ! is_string( $url_or_path ) || '' === $url_or_path ) {
			return '';
		}

		$path   = $url_or_path;
		$parsed = wp_parse_url( $url_or_path );
		if ( is_array( $parsed ) && isset( $parsed['path'] ) ) {
			$path = $parsed['path'];
		}

		if ( false !== strpos( $path, '?' ) ) {
			$path = substr( $path, 0, strpos( $path, '?' ) );
		}
		if ( false !== strpos( $path, '#' ) ) {
			$path = substr( $path, 0, strpos( $path, '#' ) );
		}

		$path = '/' . ltrim( (string) $path, '/' );
		$path = sanitize_text_field( $path );

		return $path;
	}
}
