<?php
/**
 * Class ClickTrail\Core\Storage\Setting
 *
 * @package   ClickTrail
 */

namespace CLICUTCL\Core\Storage;

/**
 * Base class for a single setting.
 */
abstract class Setting {

	/**
	 * The option_name for this setting.
	 * Override in a sub-class.
	 */
	const OPTION = '';

	/**
	 * Registers the setting in WordPress.
	 */
	public function register() {
		register_setting(
			static::OPTION,
			static::OPTION,
			array(
				'type'              => $this->get_type(),
				'sanitize_callback' => $this->get_sanitize_callback(),
				'default'           => $this->get_default(),
			)
		);
	}

	/**
	 * Gets the value of the setting.
	 *
	 * @return mixed Value set for the option, or registered default if not set.
	 */
	public function get() {
		return Option_Cache::get( static::OPTION, $this->get_default() );
	}

	/**
	 * Sets the value of the setting.
	 *
	 * @param mixed $value Setting value.
	 * @return bool True on success, false on failure.
	 */
	public function set( $value ) {
		$updated = update_option( static::OPTION, $value, $this->get_autoload() );
		if ( $updated ) {
			Option_Cache::set( static::OPTION, $value );
		}

		return $updated;
	}

	/**
	 * Whether the option should be autoloaded on every page load.
	 * Override in a sub-class to return true only when the value is needed on every request.
	 *
	 * @return bool
	 */
	protected function get_autoload() {
		return false;
	}

	/**
	 * Deletes the setting.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete() {
		$deleted = delete_option( static::OPTION );
		if ( $deleted ) {
			Option_Cache::delete( static::OPTION );
		}

		return $deleted;
	}

	/**
	 * Gets the expected value type.
	 *
	 * @return string The type name.
	 */
	protected function get_type() {
		return 'string';
	}

	/**
	 * Gets the default value.
	 *
	 * @return mixed The default value.
	 */
	protected function get_default() {
		return false;
	}

	/**
	 * Gets the callback for sanitizing the setting's value before saving.
	 *
	 * @return callable|null
	 */
	protected function get_sanitize_callback() {
		return null;
	}
}
