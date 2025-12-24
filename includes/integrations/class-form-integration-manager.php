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
		// Ensure Interface and Abstract are loaded first
		if ( ! interface_exists( 'CLICUTCL\Integrations\Forms\Form_Adapter_Interface' ) ) {
			if ( file_exists( __DIR__ . '/forms/interface-form-adapter.php' ) ) {
				require_once __DIR__ . '/forms/interface-form-adapter.php';
			}
		}

		if ( ! class_exists( 'CLICUTCL\Integrations\Forms\Abstract_Form_Adapter' ) ) {
			if ( file_exists( __DIR__ . '/forms/abstract-form-adapter.php' ) ) {
				require_once __DIR__ . '/forms/abstract-form-adapter.php';
			}
		}

		// List of available adapters
		$potential_adapters = [
			'CLICUTCL\Integrations\Forms\CF7_Adapter'           => 'forms/class-cf7-adapter.php',
			'CLICUTCL\Integrations\Forms\Fluent_Forms_Adapter'  => 'forms/class-fluent-forms-adapter.php',
			'CLICUTCL\Integrations\Forms\Gravity_Forms_Adapter' => 'forms/class-gravity-forms-adapter.php',
			'CLICUTCL\Integrations\Forms\Ninja_Forms_Adapter'   => 'forms/class-ninja-forms-adapter.php',
			'CLICUTCL\Integrations\Forms\WPForms_Adapter'       => 'forms/class-wpforms-adapter.php',
		];

		foreach ( $potential_adapters as $class => $file ) {
			// Require the file manually if class doesn't exist
			if ( ! class_exists( $class ) ) {
				$path = __DIR__ . '/' . $file;
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}

			// Instantiate if class exists after require
			if ( class_exists( $class ) ) {
				$this->adapters[] = new $class();
			}
		}
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
