<?php
/**
 * Class ClickTrail\Modules\Consent_Mode\Consent_Mode
 *
 * @package   ClickTrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace ClickTrail\Modules\Consent_Mode;

use ClickTrail\Core\Context;

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
		$consent_defaults = apply_filters(
			'clicktrail_consent_defaults',
			array(
				'ad_personalization'      => 'denied',
				'ad_storage'              => 'denied',
				'ad_user_data'            => 'denied',
				'analytics_storage'       => 'denied',
				'functionality_storage'   => 'denied',
				'security_storage'        => 'denied',
				'personalization_storage' => 'denied',
				'region'                  => $this->consent_mode_settings->get_regions(),
				'wait_for_update'         => 500,
			)
		);

		printf( "<!-- %s -->\n", esc_html__( 'Google tag (gtag.js) consent mode dataLayer added by ClickTrail', 'click-trail-handler' ) );
		
		echo '<script id="clicktrail-consent-mode">';
		echo 'window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}';
		printf( "gtag('consent', 'default', %s);", wp_json_encode( $consent_defaults ) );
		echo '</script>';
		
		printf( "<!-- %s -->\n", esc_html__( 'End Google tag (gtag.js) consent mode dataLayer added by ClickTrail', 'click-trail-handler' ) );
	}
}
