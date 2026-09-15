<?php
/**
 * Consent Mode settings test stub.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Modules\Consent_Mode;

if ( ! class_exists( Consent_Mode_Settings::class, false ) ) {
	/**
	 * Minimal settings surface used by consent-aware unit tests.
	 */
	class Consent_Mode_Settings {
		/**
		 * Return the test consent cookie name.
		 *
		 * @return string
		 */
		public function get_cookie_name(): string {
			return 'production_consent';
		}

		/**
		 * Keep consent mode enabled for the boundary tests.
		 *
		 * @return bool
		 */
		public function is_consent_mode_enabled(): bool {
			return (bool) ( $GLOBALS['clicktrail_test_consent_mode_enabled'] ?? true );
		}

		/**
		 * Make the policy requirement controllable per test.
		 *
		 * @return bool
		 */
		public function is_consent_required_for_request(): bool {
			return (bool) ( $GLOBALS['clicktrail_test_consent_required'] ?? true );
		}
	}
}
