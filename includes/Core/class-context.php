<?php
/**
 * Class ClickTrail\Core\Context
 *
 * @package   ClickTrail
 */

namespace CLICUTCL\Core;

/**
 * Class providing context about the plugin.
 */
class Context {

	/**
	 * Main plugin file path.
	 *
	 * @var string
	 */
	private $main_file;

	/**
	 * Constructor.
	 *
	 * @param string $main_file Main plugin file path.
	 */
	public function __construct( $main_file ) {
		$this->main_file = $main_file;
	}

	/**
	 * Gets the plugin path.
	 *
	 * @return string Plugin path.
	 */
	public function path() {
		return plugin_dir_path( $this->main_file );
	}

	/**
	 * Gets the plugin URL.
	 *
	 * @return string Plugin URL.
	 */
	public function url() {
		return plugin_dir_url( $this->main_file );
	}

	/**
	 * Gets the plugin basename.
	 *
	 * @return string Plugin basename.
	 */
	public function basename() {
		return plugin_basename( $this->main_file );
	}
}
