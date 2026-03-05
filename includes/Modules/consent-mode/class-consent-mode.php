<?php

namespace CLICUTCL\Modules\Consent_Mode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CLICUTCL\Core\Context;

/**
 * Class ClickTrail\Modules\Consent_Mode\Consent_Mode
 *
 * @package   ClickTrail
 */

/**
 * Class for handling consent mode.
 */
class Consent_Mode {

	/**
	 * Context instance.
	 *
	 * @var Context
	 */
	protected $context;

	/**
	 * Consent_Mode_Settings instance.
	 *
	 * @var Consent_Mode_Settings
	 */
	protected $consent_mode_settings;

	/**
	 * Constructor.
	 *
	 * @param Context $context Plugin context.
	 */
	public function __construct( Context $context ) {
		$this->context               = $context;
		$this->consent_mode_settings = new Consent_Mode_Settings();
	}

	/**
	 * Registers functionality through WordPress hooks.
	 */
	public function register() {
		$this->consent_mode_settings->register();

		// Declare that the plugin is compatible with the WP Consent API.
		$plugin = $this->context->basename();
		add_filter( "wp_consent_api_registered_{$plugin}", '__return_true' );

		if ( $this->consent_mode_settings->is_consent_mode_enabled() ) {
			add_action( 'wp_head', array( $this, 'render_gtag_consent_data_layer_snippet' ), 1 );
		}
	}

	/**
	 * Prints the gtag consent snippet.
	 */
	public function render_gtag_consent_data_layer_snippet() {
		$mode            = $this->consent_mode_settings->get_mode();
		$wait_for_update = 500;
		$strict_defaults = $this->build_consent_defaults( 'strict', $wait_for_update );
		$relaxed_defaults = $this->build_consent_defaults( 'relaxed', $wait_for_update );
		$global_defaults = $strict_defaults;
		$region_defaults = array();

		if ( 'relaxed' === $mode ) {
			$global_defaults = $relaxed_defaults;
		} elseif ( 'geo' === $mode ) {
			$regions = $this->consent_mode_settings->get_regions();
			if ( ! empty( $regions ) ) {
				$region_defaults           = $strict_defaults;
				$region_defaults['region'] = $regions;
				$global_defaults           = $relaxed_defaults;
			}
		}

		$global_defaults = apply_filters( 'clicutcl_consent_defaults', $global_defaults, $mode );
		if ( ! empty( $region_defaults ) ) {
			$region_defaults = apply_filters( 'clicutcl_consent_region_defaults', $region_defaults, $mode );
		}

		printf( "<!-- %s -->\n", esc_html__( 'Google tag (gtag.js) consent mode dataLayer added by ClickTrail', 'click-trail-handler' ) );
		
		echo '<script id="clicktrail-consent-mode">';
		echo 'window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}';
		if ( ! empty( $region_defaults ) ) {
			printf( "gtag('consent', 'default', %s);", wp_json_encode( $region_defaults ) );
		}
		printf( "gtag('consent', 'default', %s);", wp_json_encode( $global_defaults ) );
		echo '</script>';
		
		printf( "<!-- %s -->\n", esc_html__( 'End Google tag (gtag.js) consent mode dataLayer added by ClickTrail', 'click-trail-handler' ) );
	}

	/**
	 * Build gtag default consent payload for a mode.
	 *
	 * @param string $mode Mode.
	 * @param int    $wait_for_update Wait for update in ms.
	 * @return array
	 */
	private function build_consent_defaults( string $mode, int $wait_for_update ): array {
		if ( 'relaxed' === $mode ) {
			return array(
				'ad_personalization'      => 'denied',
				'ad_storage'              => 'denied',
				'ad_user_data'            => 'denied',
				'analytics_storage'       => 'granted',
				'functionality_storage'   => 'granted',
				'security_storage'        => 'granted',
				'personalization_storage' => 'granted',
				'wait_for_update'         => $wait_for_update,
			);
		}

		return array(
			'ad_personalization'      => 'denied',
			'ad_storage'              => 'denied',
			'ad_user_data'            => 'denied',
			'analytics_storage'       => 'denied',
			'functionality_storage'   => 'denied',
			'security_storage'        => 'denied',
			'personalization_storage' => 'denied',
			'wait_for_update'         => $wait_for_update,
		);
	}
}
