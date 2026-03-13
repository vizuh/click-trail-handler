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
		$event['identity']    = isset( $data['identity'] ) && is_array( $data['identity'] ) ? $data['identity'] : array();
		$event['consent']     = isset( $data['consent'] ) && is_array( $data['consent'] ) ? $data['consent'] : array();
		$event['meta']        = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		if ( empty( $event['identity'] ) && ! empty( $event['meta']['identity'] ) && is_array( $event['meta']['identity'] ) ) {
			$event['identity'] = $event['meta']['identity'];
		}

		if ( ! empty( $event['identity'] ) && empty( $event['meta']['identity'] ) ) {
			$event['meta']['identity'] = $event['identity'];
		}

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
			'optional' => array( 'page', 'wa', 'form', 'commerce', 'attribution', 'identity', 'consent', 'meta' ),
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

		$meta = array(
			'site_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'plugin_version' => defined( 'CLICUTCL_VERSION' ) ? CLICUTCL_VERSION : '',
		);

		if ( isset( $context['session_id'] ) && is_scalar( $context['session_id'] ) ) {
			$meta['session_id'] = sanitize_text_field( (string) $context['session_id'] );
		}

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
			'identity'    => isset( $context['identity'] ) && is_array( $context['identity'] ) ? $context['identity'] : array(),
			'meta'        => $meta,
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

		$commerce_source = isset( $payload['commerce'] ) && is_array( $payload['commerce'] ) ? $payload['commerce'] : array();
		$event_name      = isset( $payload['event_name'] ) ? sanitize_key( (string) $payload['event_name'] ) : 'purchase';
		$event_name      = '' !== $event_name ? $event_name : 'purchase';
		$order_id       = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
		$transaction_id = isset( $commerce_source['transaction_id'] )
			? sanitize_text_field( (string) $commerce_source['transaction_id'] )
			: ( isset( $payload['transaction_id'] ) ? sanitize_text_field( (string) $payload['transaction_id'] ) : '' );
		$event_id       = isset( $payload['event_id'] ) ? sanitize_text_field( (string) $payload['event_id'] ) : '';
		$event_id       = $event_id ? $event_id : ( $transaction_id ? $event_name . '_' . $transaction_id : ( $order_id ? $event_name . '_' . $order_id : self::generate_id( $event_name ) ) );
		$timestamp      = isset( $payload['timestamp'] ) ? absint( $payload['timestamp'] ) : time();

		$items_source = isset( $commerce_source['items'] ) && is_array( $commerce_source['items'] )
			? $commerce_source['items']
			: ( isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array() );
		$items        = self::sanitize_purchase_items( $items_source );
		$commerce     = self::sanitize_nested_array( $commerce_source );
		$meta         = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? self::sanitize_nested_array( $payload['meta'] ) : array();

		$commerce['transaction_id'] = $transaction_id;
		$commerce['value']          = isset( $commerce_source['value'] ) ? (float) $commerce_source['value'] : ( isset( $payload['value'] ) ? (float) $payload['value'] : 0.0 );
		$commerce['currency']       = isset( $commerce_source['currency'] ) ? sanitize_text_field( (string) $commerce_source['currency'] ) : ( isset( $payload['currency'] ) ? sanitize_text_field( (string) $payload['currency'] ) : '' );
		$commerce['items']          = $items;

		foreach ( array( 'subtotal', 'tax_total', 'shipping_total', 'discount_total' ) as $field ) {
			if ( isset( $commerce_source[ $field ] ) ) {
				$commerce[ $field ] = (float) $commerce_source[ $field ];
				continue;
			}
			if ( isset( $payload[ $field ] ) ) {
				$commerce[ $field ] = (float) $payload[ $field ];
			}
		}

		if ( isset( $commerce_source['discount_codes'] ) && is_array( $commerce_source['discount_codes'] ) ) {
			$commerce['discount_codes'] = self::sanitize_string_array( $commerce_source['discount_codes'] );
		} elseif ( isset( $payload['discount_codes'] ) && is_array( $payload['discount_codes'] ) ) {
			$commerce['discount_codes'] = self::sanitize_string_array( $payload['discount_codes'] );
		}

		foreach ( array( 'status', 'order_currency' ) as $field ) {
			if ( isset( $commerce_source[ $field ] ) ) {
				$commerce[ $field ] = sanitize_text_field( (string) $commerce_source[ $field ] );
				continue;
			}
			if ( isset( $payload[ $field ] ) ) {
				$commerce[ $field ] = sanitize_text_field( (string) $payload[ $field ] );
			}
		}

		if ( isset( $commerce_source['item_quantity'] ) ) {
			$commerce['item_quantity'] = absint( $commerce_source['item_quantity'] );
		} elseif ( isset( $payload['item_quantity'] ) ) {
			$commerce['item_quantity'] = absint( $payload['item_quantity'] );
		}

		if ( isset( $payload['customer_id'] ) && ! isset( $meta['customer_id'] ) ) {
			$meta['customer_id'] = absint( $payload['customer_id'] );
		}
		foreach ( array( 'customer_created_at', 'order_created_at' ) as $field ) {
			if ( isset( $payload[ $field ] ) && ! isset( $meta[ $field ] ) ) {
				$meta[ $field ] = sanitize_text_field( (string) $payload[ $field ] );
			}
		}

		$data = array(
			'event_name' => $event_name,
			'event_id'   => $event_id,
			'timestamp'  => $timestamp,
			'source'     => isset( $payload['source'] ) ? sanitize_text_field( (string) $payload['source'] ) : 'server',
			'commerce'   => $commerce,
			'attribution' => isset( $payload['attribution'] ) && is_array( $payload['attribution'] ) ? self::sanitize_nested_array( $payload['attribution'] ) : array(),
			'identity'    => isset( $payload['identity'] ) && is_array( $payload['identity'] ) ? self::sanitize_nested_array( $payload['identity'] ) : array(),
			'meta'        => array_merge(
				$meta,
				array(
					'order_id'       => $order_id,
					'site_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
					'plugin_version' => defined( 'CLICUTCL_VERSION' ) ? CLICUTCL_VERSION : '',
				)
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
	 * Sanitize nested scalar arrays while preserving additive custom keys.
	 *
	 * @param mixed $value Raw payload value.
	 * @return array
	 */
	private static function sanitize_nested_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$out[ $key ] = self::sanitize_nested_array( $item );
				continue;
			}

			if ( is_bool( $item ) ) {
				$out[ $key ] = $item;
				continue;
			}

			if ( is_numeric( $item ) ) {
				$out[ $key ] = $item + 0;
				continue;
			}

			$out[ $key ] = sanitize_text_field( (string) $item );
		}

		return $out;
	}

	/**
	 * Sanitize purchase items while preserving the additive WooCommerce keys.
	 *
	 * @param array $items Raw item list.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_purchase_items( array $items ) {
		$sanitized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$row = self::sanitize_nested_array( $item );
			$row['item_id'] = isset( $item['item_id'] ) ? sanitize_text_field( (string) $item['item_id'] ) : '';
			$row['item_name'] = isset( $item['item_name'] ) ? sanitize_text_field( (string) $item['item_name'] ) : '';
			$row['price'] = isset( $item['price'] ) ? (float) $item['price'] : 0.0;
			$row['quantity'] = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

			if ( isset( $item['product_id'] ) ) {
				$row['product_id'] = absint( $item['product_id'] );
			}
			if ( isset( $item['sku'] ) ) {
				$row['sku'] = sanitize_text_field( (string) $item['sku'] );
			}
			if ( isset( $item['variant'] ) ) {
				$row['variant'] = sanitize_text_field( (string) $item['variant'] );
			}
			if ( isset( $item['categories'] ) && is_array( $item['categories'] ) ) {
				$row['categories'] = self::sanitize_string_array( $item['categories'] );
			}
			if ( isset( $item['item_list_name'] ) ) {
				$row['item_list_name'] = sanitize_text_field( (string) $item['item_list_name'] );
			}
			if ( isset( $item['item_list_index'] ) ) {
				$row['item_list_index'] = absint( $item['item_list_index'] );
			}

			$sanitized[] = $row;
		}

		return $sanitized;
	}

	/**
	 * Sanitize an array of string values.
	 *
	 * @param array $values Raw values.
	 * @return array<int, string>
	 */
	private static function sanitize_string_array( array $values ) {
		return array_values(
			array_filter(
				array_map(
					static function( $value ) {
						return sanitize_text_field( (string) $value );
					},
					$values
				)
			)
		);
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
