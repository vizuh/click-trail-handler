<?php
/**
 * Generic Collector Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Generic_Collector_Adapter
 */
class Generic_Collector_Adapter implements Adapter_Interface {
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
		return 'generic_collector';
	}

	/**
	 * Send event to endpoint.
	 *
	 * @param Event $event Event.
	 * @return Adapter_Result
	 */
	public function send( Event $event ) {
		$body = $event->to_array();
		$body['schema_version'] = 1;

		$response = wp_remote_post(
			$this->endpoint,
			array(
				'timeout' => $this->timeout,
				'headers' => array(
					'content-type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return Adapter_Result::error( 0, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$ok     = $status >= 200 && $status < 300;

		return new Adapter_Result(
			$ok,
			$status,
			$ok ? 'sent' : 'error',
			array(
				'endpoint' => $this->endpoint,
			)
		);
	}

	/**
	 * Health check.
	 *
	 * @return Adapter_Result
	 */
	public function health_check() {
		$response = wp_remote_request(
			$this->endpoint,
			array(
				'timeout' => $this->timeout,
				'method'  => 'GET',
			)
		);

		if ( is_wp_error( $response ) ) {
			return Adapter_Result::error( 0, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$ok     = $status >= 200 && $status < 400;

		return new Adapter_Result(
			$ok,
			$status,
			$ok ? 'reachable' : 'unreachable',
			array(
				'endpoint' => $this->endpoint,
			)
		);
	}
}
