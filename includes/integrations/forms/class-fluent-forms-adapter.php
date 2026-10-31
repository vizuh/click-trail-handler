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
		// Fluent Forms fires its legacy and slash-style render hooks for the same form.
		static $last_form = null;
		if ( is_object( $form ) ) {
			if ( $last_form === $form ) {
				return;
			}
			$last_form = $form;
		}

		// Values are empty unless the render filter opts in (#152); names are always emitted.
		foreach ( $this->get_render_payload() as $key => $value ) {
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
	 * Resolve the ct_* keys of the response data at submit time (#152).
	 *
	 * Runs on `fluentform/insert_response_data` before Fluent Forms stores the entry, so the
	 * native entry, `fluentform_submission_meta` and ClickTrail's log hold the same value. Readable
	 * cookie: authoritative, keys it lacks are blanked. No cookie: posted values survive only with
	 * the `ct_fill_source=js` marker. Consent blocked: every `ct_*` blanked. The marker is stripped.
	 * Only `ct_*` names the form posted are rewritten.
	 *
	 * @param array $form_data     Response data about to be inserted.
	 * @param int   $form_id       Form ID (unused).
	 * @param array $input_configs Entry input configs (unused).
	 * @return array
	 */
	public function refresh_response_data( $form_data, $form_id = 0, $input_configs = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $form_data ) ) {
			return $form_data;
		}

		$marker = isset( $form_data[ self::FILL_MARKER_FIELD ] ) && $this->has_fill_marker( $form_data[ self::FILL_MARKER_FIELD ] );
		unset( $form_data[ self::FILL_MARKER_FIELD ] );

		$posted = array();
		foreach ( $form_data as $name => $value ) {
			if ( 0 === strpos( (string) $name, $this->field_prefix ) ) {
				$posted[ substr( (string) $name, strlen( $this->field_prefix ) ) ] = $value;
			}
		}
		if ( empty( $posted ) ) {
			return $form_data;
		}

		$resolved = $this->resolve_submitted_attribution( $posted, $marker );
		foreach ( array_keys( $posted ) as $key ) {
			$form_data[ $this->get_field_name( $key ) ] = $resolved[ $key ];
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

		static $logged = array();
		$entry_id      = (int) $arg1;
		if ( isset( $logged[ $entry_id ] ) ) {
			return;
		}
		$logged[ $entry_id ] = true;

		$form_data = is_array( $arg2 ) ? $arg2 : array();
		$form      = $arg3;
		$payload   = $this->get_attribution_payload();

		// Re-apply the rule refresh_response_data() applied before insert (pure, idempotent). If
		// this hook receives the already-refreshed copy the marker is gone, which can only blank,
		// never leak.
		$marker = isset( $form_data[ self::FILL_MARKER_FIELD ] ) && $this->has_fill_marker( $form_data[ self::FILL_MARKER_FIELD ] );
		unset( $form_data[ self::FILL_MARKER_FIELD ] );
		$posted = array();
		foreach ( $form_data as $name => $value ) {
			if ( 0 === strpos( (string) $name, $this->field_prefix ) ) {
				$posted[ substr( (string) $name, strlen( $this->field_prefix ) ) ] = $value;
			}
		}
		$carries_ct_field = ! empty( $posted );
		$attribution      = array();

		if ( $carries_ct_field ) {
			foreach ( $this->resolve_submitted_attribution( $posted, $marker ) as $key => $value ) {
				if ( '' !== $value && array_key_exists( $key, $posted ) ) {
					$attribution[ $key ] = $value;
				}
			}
		}

		// A form with no ct_* field keeps today's record: ClickTrail's own log holds the cookie.
		if ( ! $carries_ct_field ) {
			$attribution = $payload;
		}

		if ( empty( $attribution ) ) {
			return;
		}

		// 1. Persist to Fluent Forms submission meta so attribution values are
		// accessible in the Fluent Forms entry detail view and via its API.
		// wpFluent() is Fluent's own DB wrapper and is always available when the
		// plugin is active. The table is created by Fluent on activation.
		if ( function_exists( 'wpFluent' ) ) {
			$now = current_time( 'mysql' );
			foreach ( $attribution as $key => $value ) {
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
					// Non-fatal: ClickTrail logging below still proceeds.
				}
			}
		}

		// 2. Log to ClickTrail
		$form_id_val = isset( $form->id ) ? $form->id : 0;
		$this->log_submission( 'fluentform', $form_id_val, $attribution, $form_data );
	}
}
