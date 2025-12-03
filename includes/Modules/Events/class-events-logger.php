<?php
/**
 * Class ClickTrail\\Modules\\Events\\Events_Logger
 *
 * @package   ClickTrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace ClickTrail\Modules\Events;

use ClickTrail\Core\Context;

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
				'event'   => 'login',
				'user_id' => $user->ID,
				'method'  => 'wordpress',
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
				'event'   => 'sign_up',
				'user_id' => $user_id,
				'method'  => 'wordpress',
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
				'event'      => 'comment_submit',
				'comment_id' => $comment_id,
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
		$pushed_events = array();

		foreach ( $events as $cookie_name ) {
			if ( isset( $_COOKIE[ $cookie_name ] ) ) {
				$event_data = json_decode( sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ), true );
				
				if ( $event_data ) {
					$pushed_events[] = $event_data;
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

		if ( ! empty( $pushed_events ) ) {
			echo "<script>\n";
			echo "window.dataLayer = window.dataLayer || [];\n";
			foreach ( $pushed_events as $event ) {
				printf( "window.dataLayer.push(%s);\n", wp_json_encode( $event ) );
			}
			echo "</script>\n";
		}
	}
}
