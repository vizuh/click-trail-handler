<?php
/**
 * Server-side Settings Helper
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

use CLICUTCL\Core\Storage\Option_Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {
	/**
	 * Site option key.
	 */
	const OPTION_SITE = 'clicutcl_server_side';

	/**
	 * Network option key.
	 */
	const OPTION_NETWORK = 'clicutcl_server_side_network';

	/**
	 * Get effective settings (network or site).
	 *
	 * @return array
	 */
	public static function get() {
		static $site_cache = null;
		static $network_cache = null;

		if ( null === $site_cache ) {
			$site_cache = Option_Cache::get( self::OPTION_SITE, array() );
		}

		$site = is_array( $site_cache ) ? $site_cache : array();

		if ( is_multisite() ) {
			$use_network = ! isset( $site['use_network'] ) || (int) $site['use_network'] === 1;
			if ( null === $network_cache ) {
				$network_cache = get_site_option( self::OPTION_NETWORK, array() );
			}
			$network = is_array( $network_cache ) ? $network_cache : array();

			if ( $use_network && ! empty( $network ) ) {
				return $network;
			}
		}

		return $site;
	}

	/**
	 * Get network settings.
	 *
	 * @return array
	 */
	public static function get_network() {
		static $network_cache = null;

		if ( ! is_multisite() ) {
			return array();
		}

		if ( null === $network_cache ) {
			$network_cache = get_site_option( self::OPTION_NETWORK, array() );
		}

		$network = $network_cache;
		return is_array( $network ) ? $network : array();
	}
}
