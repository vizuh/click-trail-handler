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
	 * on_submission relies on the shared (provider, form, entry) dedupe in log_submission() so
	 * it never logs or writes meta for the same entry twice even when both aliases fire on an
	 * installation that preserves both.
	 */
	public function register_hooks() {
		// Slash-style (Fluent Forms v5+, current docs).
		add_action( 'fluentform/form_element_start', array( $this, 'add_hidden_fields' ), 10, 1 );
		add_filter( 'fluentform/insert_response_data', array( $this, 'refresh_response_data' ), 10, 3 );
		add_action( 'fluentform/submission_inserted', array( $this, 'on_submission' ), 10, 3 );

		// Underscore-style (legacy aliases, v4 and below).
		add_action( 'fluentform_form_element_start', array( $this, 'add_hidden_fields' ), 10, 1 );
		add_filter( 'fluentform_insert_response_data', array( $this, 'refresh_response_data' ), 10, 3 );
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
		if ( empty( $payload ) ) {
			return;
		}

		// Fluent Forms fires its legacy and slash-style render hooks for the same form.
		static $last_form = null;
		if ( is_object( $form ) ) {
			if ( $last_form === $form ) {
				return;
			}
			$last_form = $form;
		}

		foreach ( $payload as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $this->get_field_name( $key ) ) . '" value="' . esc_attr( $value ) . '">';
		}
	}

	/**
	 * Interface compliance.
	 *
	 * @param mixed $form_or_context Form or context passed through unchanged.
	 * @return mixed
	 */
	public function populate_fields( $form_or_context ) {
		return $form_or_context;
	}

	/**
	 * Refresh each ct_* key of the response data from the cookie sent with the submit.
	 *
	 * Runs on `fluentform/insert_response_data` before Fluent Forms stores the entry, so the
	 * native entry, `fluentform_submission_meta` and ClickTrail's log hold the same submit-time
	 * value. Per key: a non-empty cookie key wins, keys the cookie lacks keep the posted value.
	 * Pure in (posted, cookie), so the legacy alias firing a second time is a no-op.
	 *
	 * @param array $form_data     Response data about to be inserted.
	 * @param int   $form_id       Form ID (unused).
	 * @param array $input_configs Entry input configs (unused).
	 * @return array
	 */
	public function refresh_response_data( $form_data, $form_id = 0, $input_configs = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $form_data ) || ! $this->refresh_allowed() ) {
			return $form_data;
		}

		$payload = $this->get_attribution_payload();
		if ( empty( $payload ) ) {
			return $form_data;
		}

		foreach ( $form_data as $name => $posted ) {
			$value = $this->cookie_value_for( $name, $payload );
			if ( null !== $value ) {
				$form_data[ $name ] = sanitize_text_field( $value );
			}
		}

		return $form_data;
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
	 * @param int    $arg1 Submission ID (mapped to arg1).
	 * @param array  $arg2 Posted data (mapped to arg2).
	 * @param object $arg3 Form object (optional).
	 */
	public function on_submission( $arg1, $arg2, $arg3 = null ) {
		if ( ! $this->refresh_allowed() ) {
			return;
		}

		$entry_id  = (int) $arg1;
		$form_data = is_array( $arg2 ) ? $arg2 : array();
		$form      = $arg3;
		$payload   = $this->get_attribution_payload();

		// Per key, the same rule refresh_response_data() applied before insert: a non-empty cookie
		// key wins, keys the cookie lacks keep the posted value. Re-applying it here is idempotent.
		$attribution      = array();
		$carries_ct_field = false;

		foreach ( Attribution_Provider::get_field_mapping() as $key ) {
			$prefixed = $this->get_field_name( $key );
			if ( ! isset( $form_data[ $prefixed ] ) ) {
				continue;
			}
			$carries_ct_field = true;

			$value = $this->cookie_value_for( $prefixed, $payload );
			if ( null === $value ) {
				$value = is_scalar( $form_data[ $prefixed ] ) ? (string) $form_data[ $prefixed ] : '';
			}
			$value = sanitize_text_field( $value );
			if ( '' !== $value ) {
				$attribution[ $key ] = $value;
			}
		}

		// A form with no ct_* field keeps today's record: ClickTrail's own log holds the cookie.
		if ( ! $carries_ct_field ) {
			$attribution = $payload;
		}

		if ( empty( $attribution ) ) {
			return;
		}

		// 1. Log to ClickTrail. A repeat of the same entry (both hook aliases firing) stops here:
		// its meta was written by the first call.
		$form_id_val = isset( $form->id ) ? $form->id : 0;
		$logged      = $this->log_submission( 'fluentform', $form_id_val, $attribution, $form_data, $entry_id );
		if ( ! empty( $logged['duplicate'] ) ) {
			return;
		}

		// 2. Persist to Fluent Forms submission meta so attribution values are
		// accessible in the Fluent Forms entry detail view and via its API.
		// wpFluent() is Fluent's own DB wrapper and is always available when the
		// plugin is active. The table is created by Fluent on activation.
		if ( function_exists( 'wpFluent' ) ) {
			$now = current_time( 'mysql' );
			foreach ( array_merge( $attribution, $this->entry_id_meta( $logged ) ) as $key => $value ) {
				try {
					wpFluent()
						->table( 'fluentform_submission_meta' )
						->insert(
							array(
								'response_id' => $entry_id,
								'meta_key'    => $this->get_field_name( $key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fluent Forms schema requires meta_key column.
								'value'       => (string) $value,
								'created_at'  => $now,
								'updated_at'  => $now,
							)
						);
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Non-fatal: ClickTrail logging already ran above.
				}
			}
		}
	}
}
