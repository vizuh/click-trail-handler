<?php
/**
 * Fluent Forms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Fluent_Forms_Adapter
 */
class Fluent_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Check if Fluent Forms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'FLUENTFORM' ) || class_exists( '\FluentForm\Framework\Foundation\Application' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Fluent Forms';
	}

	/**
	 * Register hooks.
	 *
	 * Fluent Forms v5+ uses slash-style hooks (fluentform/form_element_start).
	 * v4 and earlier used underscore-style (fluentform_form_element_start).
	 * Both sets are registered so the adapter works regardless of installed version.
	 * on_submission carries a static dedup guard so it never logs the same entry twice
	 * even when both aliases fire on an installation that preserves both.
	 */
	public function register_hooks() {
		// Slash-style (Fluent Forms v5+, current docs)
		add_action( 'fluentform/form_element_start', array( $this, 'add_hidden_fields' ), 10, 1 );
		add_action( 'fluentform/submission_inserted', array( $this, 'on_submission' ), 10, 3 );

		// Underscore-style (legacy aliases, v4 and below)
		add_action( 'fluentform_form_element_start', array( $this, 'add_hidden_fields' ), 10, 1 );
		add_action( 'fluentform_submission_inserted', array( $this, 'on_submission' ), 10, 3 );
	}

	/**
	 * Add hidden fields to Fluent Form.
	 *
	 * @param object $form Form object.
	 */
	public function add_hidden_fields( $form ) {
		if ( ! $this->should_populate() ) {
			return;
		}

		$payload = $this->get_attribution_payload();
		
		foreach ( $payload as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $this->get_field_name( $key ) ) . '" value="' . esc_attr( $value ) . '">';
		}
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
	 * Handle submission (log to DB and Fluent Meta).
	 *
	 * @param int   $entry_id Submission ID.
	 * @param array $form_data Posted data.
	 * @param object $form     Form object.
	 */
	/**
	 * Handle submission (log to DB and Fluent Meta).
	 *
	 * @param int   $arg1 Submission ID (mapped to arg1).
	 * @param array $arg2 Posted data (mapped to arg2).
	 * @param object $arg3 Form object (optional).
	 */
	public function on_submission( $arg1, $arg2, $arg3 = null ) {
		static $logged = array();
		$entry_id = (int) $arg1;
		if ( isset( $logged[ $entry_id ] ) ) {
			return;
		}
		$logged[ $entry_id ] = true;

		$form_data = $arg2;
		$form = $arg3;
		// Use payload from cookie or form_data?
		// form_data should contain our hidden fields if they were submitted.
		
		$keys = Attribution_Provider::get_field_mapping();
		$attribution = array();
		
		foreach ( $keys as $key ) {
			$prefixed = $this->get_field_name( $key );
			if ( isset( $form_data[ $prefixed ] ) ) {
				$attribution[ $key ] = sanitize_text_field( $form_data[ $prefixed ] );
			}
		}

		// Fallback
		if ( empty( $attribution ) ) {
			$attribution = $this->get_attribution_payload();
		}

		if ( empty( $attribution ) ) {
			return;
		}

		// 1. Persist to Fluent Forms submission meta so attribution values are
		//    accessible in the Fluent Forms entry detail view and via its API.
		//    wpFluent() is Fluent's own DB wrapper and is always available when the
		//    plugin is active. The table is created by Fluent on activation.
		if ( function_exists( 'wpFluent' ) ) {
			$now = current_time( 'mysql' );
			foreach ( $attribution as $key => $value ) {
				try {
					wpFluent()
						->table( 'fluentform_submission_meta' )
						->insert(
							array(
								'submission_id' => $entry_id,
								'meta_key'      => $this->get_field_name( $key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fluent Forms schema requires meta_key column.
								'value'         => (string) $value,
								'created_at'    => $now,
								'updated_at'    => $now,
							)
						);
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Non-fatal: ClickTrail logging below still proceeds.
				}
			}
		}

		// 2. Log to ClickTrail
		$form_id_val = isset( $form->id ) ? $form->id : 0;
		$this->log_submission( 'fluentform', $form_id_val, $attribution, is_array( $form_data ) ? $form_data : array() );
	}
}
