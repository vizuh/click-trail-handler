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
	 * @param int   $status Status.
	 * @param string $message Message.
	 * @param array $meta Meta.
	 * @return Adapter_Result
	 */
	public static function success( $status = 200, $message = 'ok', $meta = array() ) {
		return new self( true, $status, $message, $meta, false );
	}

	/**
	 * Error result.
	 *
	 * @param int   $status Status.
	 * @param string $message Message.
	 * @param array $meta Meta.
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
}
