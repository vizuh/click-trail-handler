<?php
/**
 * Destination Adapter Interface v2
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface DestinationAdapterInterfaceV2
 */
interface DestinationAdapterInterfaceV2 {
	/**
	 * Destination key.
	 *
	 * @return string
	 */
	public function get_destination_key(): string;

	/**
	 * Map canonical event to destination schema.
	 *
	 * @param array $canonical_event Canonical event v2.
	 * @return array
	 */
	public function map_event( array $canonical_event ): array;

	/**
	 * Send mapped payload.
	 *
	 * @param array $mapped_payload Destination payload.
	 * @return mixed
	 */
	public function send_mapped( array $mapped_payload );
}

