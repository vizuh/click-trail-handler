<?php
/**
 * Class ClickTrail\Modules\GTM\GTM_Settings
 *
 * @package   ClickTrail
 */

namespace ClickTrail\Modules\GTM;

use ClickTrail\Core\Storage\Setting;

/**
 * Class to store GTM settings.
 */
class GTM_Settings extends Setting {

	/**
	 * The user option name for this setting.
	 */
	const OPTION = 'clicktrail_gtm';

	/**
	 * Gets the expected value type.
	 *
	 * @return string The type name.
	 */
	protected function get_type() {
		return 'object';
	}

	/**
	 * Gets the default value.
	 *
	 * @return array The default value.
	 */
	protected function get_default() {
		return array(
			'container_id' => '',
		);
	}

	/**
	 * Gets the callback for sanitizing the setting's value before saving.
	 *
	 * @return callable Sanitize callback.
	 */
	protected function get_sanitize_callback() {
		return function ( $value ) {
			$new_value = $this->get();

			if ( isset( $value['container_id'] ) ) {
				$new_value['container_id'] = sanitize_text_field( $value['container_id'] );
			}

			return $new_value;
		};
	}

	/**
	 * Accessor for the `container_id` setting.
	 *
	 * @return string GTM Container ID.
	 */
	public function get_container_id() {
		$settings = $this->get();
		return isset( $settings['container_id'] ) ? $settings['container_id'] : '';
	}
}
