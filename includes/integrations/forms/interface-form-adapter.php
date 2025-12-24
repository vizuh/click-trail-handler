<?php
/**
 * Form Adapter Interface
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Form_Adapter_Interface
 */
interface Form_Adapter_Interface {

	/**
	 * Check if the form plugin is active.
	 *
	 * @return bool
	 */
	public function is_active();

	/**
	 * Get the platform name (e.g., 'Gravity Forms').
	 *
	 * @return string
	 */
	public function get_platform_name();

	/**
	 * Register hooks for this adapter.
	 */
	public function register_hooks();

	/**
	 * Populate fields in the form.
	 *
	 * @param mixed $form_or_context The form object or context.
	 * @return mixed The modified form or value.
	 */
	public function populate_fields( $form_or_context );

	/**
	 * Handle form submission.
	 *
	 * @param mixed $submission_data The submission data.
	 * @param mixed $form_id The form ID.
	 */
	/**
	 * Handle form submission.
	 *
	 * Note: Arguments vary by platform.
	 *
	 * @return void
	 */
	public function on_submission( $arg1, $arg2 );
}
