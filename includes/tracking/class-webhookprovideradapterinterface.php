<?php
/**
 * Webhook Provider Adapter Interface
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface WebhookProviderAdapterInterface
 */
interface WebhookProviderAdapterInterface {
	/**
	 * Provider key.
	 *
	 * @return string
	 */
	public function get_provider_key(): string;

	/**
	 * Check if payload can be handled.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	public function supports( array $payload ): bool;

	/**
	 * Map provider webhook payload into canonical event v2 payload.
	 *
	 * @param array $payload Raw provider payload.
	 * @return array
	 */
	public function map_to_canonical( array $payload ): array;
}

