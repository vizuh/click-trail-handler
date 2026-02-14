<?php
/**
 * Google Ads / GA4 Adapter (native path scaffold).
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Google_Ads_Adapter
 */
class Google_Ads_Adapter implements Adapter_Interface {
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
	 * @param int    $timeout Timeout.
	 */
	public function __construct( $endpoint, $timeout = 5 ) {
		$this->endpoint = (string) $endpoint;
		$this->timeout  = max( 1, (int) $timeout );
	}

	/**
	 * Adapter key.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'google_ads';
	}

	/**
	 * Send event.
	 *
	 * @param Event $event Canonical event.
	 * @return Adapter_Result
	 */
	public function send( Event $event ) {
		$body = $event->to_array();
		$body['schema_version'] = 2;
		$body['collector']      = 'google_ads';

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
			return Adapter_Result::error( 0, $response->get_error_message(), array( 'endpoint' => $this->endpoint ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$ok     = $status >= 200 && $status < 300;
		return new Adapter_Result( $ok, $status, $ok ? 'sent' : 'error', array( 'endpoint' => $this->endpoint ) );
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
			return Adapter_Result::error( 0, $response->get_error_message(), array( 'endpoint' => $this->endpoint ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$ok     = $status >= 200 && $status < 400;
		return new Adapter_Result( $ok, $status, $ok ? 'reachable' : 'unreachable', array( 'endpoint' => $this->endpoint ) );
	}
}

