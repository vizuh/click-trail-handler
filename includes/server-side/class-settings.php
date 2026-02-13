<?php
/**
 * Server-side Settings Helper
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side;

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
		$site = get_option( self::OPTION_SITE, array() );

		if ( is_multisite() ) {
			$use_network = ! isset( $site['use_network'] ) || (int) $site['use_network'] === 1;
			$network     = get_site_option( self::OPTION_NETWORK, array() );

			if ( $use_network && is_array( $network ) && ! empty( $network ) ) {
				return $network;
			}
		}

		return is_array( $site ) ? $site : array();
	}

	/**
	 * Get network settings.
	 *
	 * @return array
	 */
	public static function get_network() {
		if ( ! is_multisite() ) {
			return array();
		}

		$network = get_site_option( self::OPTION_NETWORK, array() );
		return is_array( $network ) ? $network : array();
	}
}
