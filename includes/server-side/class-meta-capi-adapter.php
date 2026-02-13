<?php
/**
 * Meta CAPI Adapter (stub)
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meta_Capi_Adapter
 */
class Meta_Capi_Adapter implements Adapter_Interface {
	/**
	 * Endpoint URL.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Timeout seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @param int    $timeout Timeout seconds.
	 */
	public function __construct( $endpoint, $timeout = 5 ) {
		$this->endpoint = (string) $endpoint;
		$this->timeout  = max( 1, (int) $timeout );
	}

	/**
	 * Adapter name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'meta_capi';
	}

	/**
	 * Send event (stub).
	 *
	 * @param Event $event Event.
	 * @return Adapter_Result
	 */
	public function send( Event $event ) {
		return Adapter_Result::skipped( 'meta_capi_not_configured' );
	}

	/**
	 * Health check (stub).
	 *
	 * @return Adapter_Result
	 */
	public function health_check() {
		return Adapter_Result::error( 0, 'meta_capi_not_configured' );
	}
}
