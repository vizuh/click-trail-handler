<?php
/**
 * Adapter Interface
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Adapter_Interface
 */
interface Adapter_Interface {
	/**
	 * Adapter name.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Send event to adapter.
	 *
	 * @param Event $event Event.
	 * @return Adapter_Result
	 */
	public function send( Event $event );

	/**
	 * Health check for adapter.
	 *
	 * @return Adapter_Result
	 */
	public function health_check();
}
