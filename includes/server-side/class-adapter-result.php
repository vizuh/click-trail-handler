<?php
/**
 * Adapter Result
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_Result
 */
class Adapter_Result {
	/**
	 * Success flag.
	 *
	 * @var bool
	 */
	public $success;

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Message.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Meta data.
	 *
	 * @var array
	 */
	public $meta;

	/**
	 * Skipped flag.
	 *
	 * @var bool
	 */
	public $skipped;

	/**
	 * Whether a failed send may succeed on retry.
	 *
	 * False for terminal client errors (4xx other than 408/425/429), where
	 * re-sending the same payload cannot succeed. Irrelevant for successes.
	 *
	 * @var bool
	 */
	public $retryable = true;

	/**
	 * Constructor.
	 *
	 * @param bool   $success Success.
	 * @param int    $status Status.
	 * @param string $message Message.
	 * @param array  $meta Meta.
	 * @param bool   $skipped Skipped.
	 */
	public function __construct( $success, $status, $message, $meta = array(), $skipped = false ) {
		$this->success = (bool) $success;
		$this->status  = (int) $status;
		$this->message = (string) $message;
		$this->meta    = is_array( $meta ) ? $meta : array();
		$this->skipped = (bool) $skipped;
	}

	/**
	 * Success result.
	 *
	 * @param int    $status Status.
	 * @param string $message Message.
	 * @param array  $meta Meta.
	 * @return Adapter_Result
	 */
	public static function success( $status = 200, $message = 'ok', $meta = array() ) {
		return new self( true, $status, $message, $meta, false );
	}

	/**
	 * Error result.
	 *
	 * @param int    $status Status.
	 * @param string $message Message.
	 * @param array  $meta Meta.
	 * @return Adapter_Result
	 */
	public static function error( $status, $message, $meta = array() ) {
		return new self( false, $status, $message, $meta, false );
	}

	/**
	 * Skipped result.
	 *
	 * @param string $message Message.
	 * @param array  $meta Meta.
	 * @return Adapter_Result
	 */
	public static function skipped( $message = 'skipped', $meta = array() ) {
		return new self( true, 200, $message, $meta, true );
	}

	/**
	 * Build a result from an HTTP response, distinguishing terminal failures.
	 *
	 * A 2xx status is only treated as success when the response body does not
	 * carry an unambiguous error signal (non-empty top-level `error` key or
	 * non-empty `errors` array) — collectors such as sGTM and Meta can return
	 * HTTP 200 with per-event errors, which previously counted as delivered.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body Raw response body.
	 * @param array  $meta Meta.
	 * @return Adapter_Result
	 */
	public static function from_http( $status, $body, $meta = array() ) {
		$status  = (int) $status;
		$ok      = $status >= 200 && $status < 300;
		$message = $ok ? 'sent' : 'error';

		if ( $ok ) {
			$body_error = self::extract_body_error( $body );
			if ( '' !== $body_error ) {
				$ok      = false;
				$message = 'collector_error: ' . $body_error;
			}
		}

		$result            = new self( $ok, $status, $message, $meta, false );
		$result->retryable = $ok || self::is_retryable_status( $status );

		return $result;
	}

	/**
	 * Whether an HTTP status indicates a transient (retryable) failure.
	 *
	 * @param int $status HTTP status code.
	 * @return bool
	 */
	public static function is_retryable_status( $status ) {
		$status = (int) $status;

		if ( 0 === $status ) {
			return true; // Network-level error.
		}
		if ( in_array( $status, array( 408, 425, 429 ), true ) ) {
			return true;
		}
		if ( $status >= 500 ) {
			return true;
		}
		if ( $status >= 400 ) {
			return false; // Other 4xx: the payload itself is rejected.
		}

		return true;
	}

	/**
	 * Extract an unambiguous error signal from a JSON response body.
	 *
	 * Deliberately conservative: only a non-empty top-level `error` value or
	 * a non-empty `errors` array counts, to avoid turning genuine successes
	 * into retries (which would duplicate events at the destination).
	 *
	 * @param string $body Raw response body.
	 * @return string Error detail, or empty string when none detected.
	 */
	private static function extract_body_error( $body ) {
		if ( ! is_string( $body ) || '' === trim( $body ) ) {
			return '';
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return '';
		}

		$detail = '';
		if ( ! empty( $json['error'] ) ) {
			$detail = is_scalar( $json['error'] ) ? (string) $json['error'] : (string) wp_json_encode( $json['error'] );
		} elseif ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
			$detail = (string) wp_json_encode( $json['errors'] );
		}

		if ( '' === $detail ) {
			return '';
		}

		$detail = sanitize_text_field( $detail );
		return strlen( $detail ) > 150 ? substr( $detail, 0, 150 ) : $detail;
	}
}
