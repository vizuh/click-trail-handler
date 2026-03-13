<?php

namespace CLICUTCL\Modules\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CLICUTCL\Core\Context;
use CLICUTCL\Server_Side\Event;
use CLICUTCL\Tracking\Settings as Tracking_Settings;

/**
 * Class ClickTrail\Modules\Events\Events_Logger
 *
 * @package   ClickTrail
 */

/**
 * Class for logging server-side events to dataLayer.
 */
class Events_Logger {

	/**
	 * Context instance.
	 *
	 * @var Context
	 */
	protected $context;

	/**
	 * Constructor.
	 *
	 * @param Context $context Plugin context.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Registers functionality through WordPress hooks.
	 */
	public function register() {
		add_action( 'wp_login', array( $this, 'log_login_event' ), 10, 2 );
		add_action( 'user_register', array( $this, 'log_signup_event' ), 10, 1 );
		add_action( 'comment_post', array( $this, 'log_comment_event' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'render_server_events' ), 5 );
	}

	/**
	 * Log Login Event.
	 *
	 * @param string  $user_login User Login.
	 * @param WP_User $user       User Object.
	 */
	public function log_login_event( $user_login, $user ) {
		$this->set_event_cookie(
			'ct_event_login',
			array(
				'event'    => 'login',
				'event_id' => Event::generate_id( 'login' ),
				'params'   => array(
					'user_hash' => hash( 'sha256', (string) $user->ID . wp_salt( 'auth' ) ),
					'method'    => 'wordpress',
				),
			)
		);
	}

	/**
	 * Log Signup Event.
	 *
	 * @param int $user_id User ID.
	 */
	public function log_signup_event( $user_id ) {
		$this->set_event_cookie(
			'ct_event_signup',
			array(
				'event'    => 'sign_up',
				'event_id' => Event::generate_id( 'signup' ),
				'params'   => array(
					'user_hash' => hash( 'sha256', (string) $user_id . wp_salt( 'auth' ) ),
					'method'    => 'wordpress',
				),
			)
		);
	}

	/**
	 * Log Comment Event.
	 *
	 * @param int $comment_id Comment ID.
	 * @param int $comment_approved Comment Approved Status.
	 */
	public function log_comment_event( $comment_id, $comment_approved ) {
		// Only track if approved or pending (not spam)
		if ( 'spam' === $comment_approved ) {
			return;
		}

		$this->set_event_cookie(
			'ct_event_comment',
			array(
				'event'    => 'comment_submit',
				'event_id' => Event::generate_id( 'comment' ),
				'params'   => array(
					'comment_id' => absint( $comment_id ),
				),
			)
		);
	}

	/**
	 * Set a temporary cookie to pass the event to the next page load (JS).
	 *
	 * @param string $name  Cookie name.
	 * @param array  $data  Event data.
	 */
	private function set_event_cookie( $name, $data ) {
		// Set cookie for 1 minute with security flags
		setcookie(
			$name,
			wp_json_encode( $data ),
			array(
				'expires'  => time() + 60,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Render server-side events into dataLayer.
	 */
	public function render_server_events() {
		$events        = array( 'ct_event_login', 'ct_event_signup', 'ct_event_comment' );
		$queued_events = array();

		foreach ( $events as $cookie_name ) {
			if ( isset( $_COOKIE[ $cookie_name ] ) ) {
				$event_data = json_decode( sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ), true );
				
				if ( $event_data ) {
					$queued_events[] = $this->normalize_event_cookie_payload( $event_data );
					// Clear the cookie after reading
					setcookie(
						$cookie_name,
						'',
						array(
							'expires'  => time() - 3600,
							'path'     => COOKIEPATH,
							'domain'   => COOKIE_DOMAIN,
							'secure'   => is_ssl(),
							'httponly' => true,
							'samesite' => 'Lax',
						)
					);
				}
			}
		}

		$queued_events = array_values( array_filter( $queued_events ) );
		if ( empty( $queued_events ) ) {
			return;
		}

		if ( class_exists( 'CLICUTCL\\Tracking\\Settings' ) && Tracking_Settings::browser_event_collection_enabled() ) {
			echo "<script>\n";
			printf( "window.clicutclServerEvents = (window.clicutclServerEvents || []).concat(%s);\n", wp_json_encode( $queued_events ) );
			echo "</script>\n";
			return;
		}

		echo "<script>\n";
		echo "window.dataLayer = window.dataLayer || [];\n";
		foreach ( $queued_events as $event ) {
			$event_name = isset( $event['event'] ) ? sanitize_key( (string) $event['event'] ) : '';
			$params     = isset( $event['params'] ) && is_array( $event['params'] ) ? $event['params'] : array();
			if ( ! $event_name ) {
				continue;
			}

			$legacy_payload = array_merge(
				$params,
				array(
					'event' => $event_name,
				)
			);
			if ( ! empty( $event['event_id'] ) ) {
				$legacy_payload['event_id'] = sanitize_text_field( (string) $event['event_id'] );
			}

			printf( "window.dataLayer.push(%s);\n", wp_json_encode( $legacy_payload ) );
		}
		echo "</script>\n";
	}

	/**
	 * Normalize server-event cookie payloads for the browser runtime.
	 *
	 * @param mixed $event_data Raw decoded cookie payload.
	 * @return array<string,mixed>
	 */
	private function normalize_event_cookie_payload( $event_data ) {
		if ( ! is_array( $event_data ) ) {
			return array();
		}

		$event_name = isset( $event_data['event'] ) ? sanitize_key( (string) $event_data['event'] ) : '';
		if ( '' === $event_name ) {
			return array();
		}

		$payload = array(
			'event'    => $event_name,
			'event_id' => ! empty( $event_data['event_id'] )
				? sanitize_text_field( (string) $event_data['event_id'] )
				: Event::generate_id( $event_name ),
			'params'   => array(),
		);

		if ( isset( $event_data['params'] ) && is_array( $event_data['params'] ) ) {
			$payload['params'] = $this->sanitize_scalar_array( $event_data['params'] );
			return $payload;
		}

		$legacy_params = $event_data;
		unset( $legacy_params['event'], $legacy_params['event_id'] );
		$payload['params'] = $this->sanitize_scalar_array( $legacy_params );

		return $payload;
	}

	/**
	 * Sanitize scalar browser event parameters.
	 *
	 * @param array $params Raw parameter array.
	 * @return array<string,mixed>
	 */
	private function sanitize_scalar_array( array $params ) {
		$out = array();

		foreach ( $params as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$out[ $key ] = $value;
				continue;
			}

			if ( is_numeric( $value ) ) {
				$out[ $key ] = 0 + $value;
				continue;
			}

			if ( is_scalar( $value ) ) {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $out;
	}
}
