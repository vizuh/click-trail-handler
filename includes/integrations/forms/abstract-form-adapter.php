<?php
/**
 * Abstract Form Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Abstract_Form_Adapter
 */
abstract class Abstract_Form_Adapter implements Form_Adapter_Interface {

	/**
	 * Field prefix for ClickTrail fields.
	 *
	 * @var string
	 */
	protected $field_prefix = 'ct_';

	/**
	 * Get attribution payload from core provider.
	 *
	 * @return array
	 */
	protected function get_attribution_payload() {
		return Attribution_Provider::get_payload();
	}

	/**
	 * Check if methods should populate.
	 *
	 * @return bool
	 */
	protected function should_populate() {
		return Attribution_Provider::should_populate();
	}

	/**
	 * Log submission to ClickTrail events table.
	 *
	 * @param string $platform Platform name.
	 * @param mixed  $form_id  Form ID.
	 * @param array  $attribution Attribution data associated with submission.
	 * @return void
	 */
	protected function log_submission( $platform, $form_id, $attribution ) {
		global $wpdb;

		if ( empty( $attribution ) ) {
			return; // Don't log empty attribution events? Maybe log them anyway but data is empty.
		}

		$table_name = $wpdb->prefix . 'clicutcl_events';
		
		$event_data = array(
			'platform'    => $platform,
			'form_id'     => $form_id,
			'attribution' => $attribution,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Intentional insert into custom plugin table.
		$wpdb->insert(
			$table_name,
			array(
				'event_type' => 'form_submission',
				'event_data' => wp_json_encode( $event_data ),
			)
		);
	}

	/**
	 * Get the field name with prefix.
	 *
	 * @param string $key Original key (e.g., ft_source).
	 * @return string Prefixed key (e.g., ct_ft_source).
	 */
	protected function get_field_name( $key ) {
		return $this->field_prefix . $key;
	}
}
