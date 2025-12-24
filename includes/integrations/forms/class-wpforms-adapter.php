<?php
/**
 * WPForms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPForms_Adapter
 */
class WPForms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Check if WPForms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WPForms' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'WPForms';
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		$keys = Attribution_Provider::get_field_mapping();
		
		foreach ( $keys as $key ) {
			// Filter name format: wpforms_field_value_{parameter_name}
			// When using Smart Tag {query_var key="ct_ft_source"} this hooks in?
			// Actually WPForms 'Dynamic Field Population' uses 'wpforms_field_value_$key'.
			$prefixed_key = $this->get_field_name( $key );
			add_filter( "wpforms_field_value_{$prefixed_key}", array( $this, 'populate_field' ), 10, 3 );
		}

		// Submission processing
		add_action( 'wpforms_process_complete', array( $this, 'on_submission' ), 10, 4 );
	}

	/**
	 * Populate field via filter.
	 *
	 * @param string $value     Field value.
	 * @param array  $field     Field settings.
	 * @param array  $form_data Form data.
	 * @return string
	 */
	public function populate_field( $value, $field, $form_data ) {
		if ( ! $this->should_populate() ) {
			return $value;
		}

		// We need to identify which key triggered this. 
		// Since we registered specific hooks, we can't easily know strictly from arguments 
		// unless we closures or just check the current filter name, 
		// but checking current filter is hacky.
		// A better way is to use a method that returns a closure, or `current_filter()`.
		
		$filter = current_filter();
		// Format: wpforms_field_value_ct_ft_source
		$prefix = 'wpforms_field_value_' . $this->field_prefix;
		
		if ( strpos( $filter, $prefix ) !== 0 ) {
			return $value;
		}

		$key = str_replace( $prefix, '', $filter );
		
		$payload = $this->get_attribution_payload();
		
		return isset( $payload[ $key ] ) ? $payload[ $key ] : $value;
	}

	/**
	 * Interface compliance.
	 * 
	 * @param mixed $form_or_context
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Handle submission.
	 *
	 * @param array $fields    Sanitized field data.
	 * @param array $entry     Original entry $_POST.
	 * @param array $form_data Form data.
	 * @param int   $entry_id  Entry ID.
	 */
	/**
	 * Handle submission.
	 *
	 * @param mixed $fields    Sanitized field data (mapped to arg1).
	 * @param mixed $entry     Original entry $_POST (mapped to arg2).
	 * @param mixed $form_data Form data (optional extra).
	 * @param mixed $entry_id  Entry ID (optional extra).
	 */
	public function on_submission( $fields, $entry, $form_data = null, $entry_id = null ) {
		$payload = $this->get_attribution_payload();
		if ( empty( $payload ) ) {
			return;
		}

		// Save to Entry Meta implies storing it in WPForms entry table.
		// WPForms (Lite) doesn't have entries.
		// WPForms (Pro) has entries.
		// We can try to add meta if function exists.
		
		if ( function_exists( 'wpforms' ) && isset( wpforms()->entry_meta ) ) {
			foreach ( $payload as $key => $value ) {
				$meta_key = $this->get_field_name( $key );
				wpforms()->entry_meta->add(
					array(
						'entry_id' => $entry_id,
						'form_id'  => $form_data['id'],
						'user_id'  => get_current_user_id(),
						'type'     => $meta_key,
						'data'     => $value,
					)
				);
			}
		}

		// Log to ClickTrail
		$this->log_submission( 'wpforms', $form_data['id'], $payload );
	}
}
