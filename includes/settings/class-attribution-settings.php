<?php
/**
 * Attribution Settings
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Attribution_Settings
 */
class Attribution_Settings {

	/**
	 * Option Name
	 *
	 * @var string
	 */
	const OPTION_NAME = 'clicutcl_attribution_settings';

	/**
	 * Settings data.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = get_option( self::OPTION_NAME, array() );
	}

	/**
	 * Get Cookie Duration (days).
	 *
	 * @return int
	 */
	public function get_cookie_duration() {
		return isset( $this->settings['cookie_days'] ) ? absint( $this->settings['cookie_days'] ) : 90;
	}

	/**
	 * Is Attribution Enabled?
	 *
	 * @return bool
	 */
	public function is_attribution_enabled() {
		return isset( $this->settings['enable_attribution'] ) ? (bool) $this->settings['enable_attribution'] : true;
	}

	/**
	 * Is Consent Required?
	 *
	 * @return bool
	 */
	public function is_consent_required() {
		return isset( $this->settings['require_consent'] ) ? (bool) $this->settings['require_consent'] : true;
	}

	/**
	 * Is WhatsApp Tracking Enabled?
	 *
	 * @return bool
	 */
	public function is_whatsapp_enabled() {
		return isset( $this->settings['enable_whatsapp'] ) ? (bool) $this->settings['enable_whatsapp'] : true;
	}

	/**
	 * Append Attribution to WhatsApp?
	 *
	 * @return bool
	 */
	public function append_attribution_to_whatsapp() {
		return isset( $this->settings['whatsapp_append_attribution'] ) ? (bool) $this->settings['whatsapp_append_attribution'] : false;
	}

	/**
	 * Log WhatsApp Clicks?
	 *
	 * @return bool
	 */
	public function log_whatsapp_clicks() {
		// WA click persistence was removed; keep method for backward compatibility.
		return false;
	}

	/**
	 * Enable cross-domain token?
	 *
	 * @return bool
	 */
	public function is_cross_domain_token_enabled() {
		return isset( $this->settings['enable_cross_domain_token'] ) ? (bool) $this->settings['enable_cross_domain_token'] : false;
	}
}
