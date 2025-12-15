<?php
/**
 * Form Integration Manager
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations;

use CLICUTCL\Integrations\Forms\CF7_Adapter;
use CLICUTCL\Integrations\Forms\Fluent_Forms_Adapter;
use CLICUTCL\Integrations\Forms\Gravity_Forms_Adapter;
use CLICUTCL\Integrations\Forms\Ninja_Forms_Adapter;
use CLICUTCL\Integrations\Forms\WPForms_Adapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Integration_Manager
 */
class Form_Integration_Manager {

	/**
	 * Active adapters.
	 *
	 * @var array
	 */
	private $adapters = array();

	/**
	 * Initialize the manager.
	 */
	public function init() {
		$this->register_adapters();
		$this->activate_adapters();
	}

	/**
	 * Register available adapters.
	 */
	private function register_adapters() {
		$this->adapters[] = new CF7_Adapter();
		$this->adapters[] = new Fluent_Forms_Adapter();
		$this->adapters[] = new Gravity_Forms_Adapter();
		$this->adapters[] = new Ninja_Forms_Adapter();
		$this->adapters[] = new WPForms_Adapter();
	}

	/**
	 * Activate adapters for active plugins.
	 */
	private function activate_adapters() {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->is_active() ) {
				$adapter->register_hooks();
			}
		}
	}

	/**
	 * Get active adapters.
	 *
	 * @return array
	 */
	public function get_active_adapters() {
		$active = array();
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->is_active() ) {
				$active[] = $adapter;
			}
		}
		return $active;
	}
}
