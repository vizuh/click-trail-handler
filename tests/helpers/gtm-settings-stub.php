<?php
/**
 * GTM settings test stub.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Modules\GTM;

if ( ! class_exists( GTM_Settings::class, false ) ) {
	/**
	 * Minimal GTM settings surface used by Web_Tag unit tests.
	 */
	class GTM_Settings {
		/**
		 * No-op registration for hook-ordering tests.
		 */
		public function register(): void {}

		/**
		 * Settings payload.
		 *
		 * @return array
		 */
		public function get(): array {
			return array(
				'container_id' => $this->get_container_id(),
				'mode'         => 'standard',
			);
		}

		/**
		 * Container ID, controllable per test.
		 *
		 * @return string
		 */
		public function get_container_id(): string {
			return (string) ( $GLOBALS['clicktrail_test_gtm_container_id'] ?? '' );
		}

		/**
		 * Delivery mode.
		 *
		 * @return string
		 */
		public function get_mode(): string {
			return 'standard';
		}

		/**
		 * Loader URL for the container.
		 *
		 * @param array  $settings     Settings payload (unused).
		 * @param string $container_id Container ID.
		 * @param string $data_layer   Data layer name (unused).
		 * @return string
		 */
		public static function build_script_src( array $settings, string $container_id, string $data_layer = 'dataLayer' ): string {
			return 'https://www.googletagmanager.com/gtm.js?id=' . $container_id;
		}
	}
}
