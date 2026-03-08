<?php
/**
 * Ninja Forms Adapter
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Integrations\Forms;

use CLICUTCL\Core\Attribution_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CLICUTCL\Integrations\Forms\Ninja_Forms_Submission_Extra_Handler' ) ) {
	$clicutcl_nf_handler_file = __DIR__ . '/class-ninja-forms-submission-extra-handler.php';
	if ( file_exists( $clicutcl_nf_handler_file ) ) {
		require_once $clicutcl_nf_handler_file;
	}
}

/**
 * Class Ninja_Forms_Adapter
 */
class Ninja_Forms_Adapter extends Abstract_Form_Adapter {

	/**
	 * Ninja Forms extra-data handler class.
	 *
	 * @var string
	 */
	private const EXTRA_HANDLER_CLASS = 'CLICUTCL\Integrations\Forms\Ninja_Forms_Submission_Extra_Handler';

	/**
	 * Check if Ninja Forms is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'Ninja_Forms' );
	}

	/**
	 * Get platform name.
	 *
	 * @return string
	 */
	public function get_platform_name() {
		return 'Ninja Forms';
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		// Server-side injection
		add_filter( 'ninja_forms_submit_data', array( $this, 'inject_attribution' ), 10, 2 );
		
		// Client-side tracking JS
		add_action( 'wp_footer', array( $this, 'enqueue_ninja_js' ) );

		// Log using custom table after submission
		add_action( 'ninja_forms_after_submission', array( $this, 'on_submission' ), 10, 1 );
		add_filter( 'nf_react_table_extra_value_keys', array( $this, 'register_extra_value_handler' ) );
	}

	/**
	 * Inject attribution into submission data.
	 *
	 * @param array $form_data Form data.
	 * @param array $args      Filter args.
	 * @return array
	 */
	public function inject_attribution( $form_data, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_array( $form_data ) ) {
			return $form_data;
		}

		if ( ! $this->should_populate() ) {
			return $form_data;
		}

		$payload = $this->normalize_attribution_payload( $this->get_attribution_payload() );
		
		if ( empty( $payload ) ) {
			return $form_data;
		}

		if ( ! isset( $form_data['extra'] ) || ! is_array( $form_data['extra'] ) ) {
			$form_data['extra'] = array();
		}

		$form_data['extra'][ Ninja_Forms_Submission_Extra_Handler::EXTRA_VALUE_KEY ] = $payload;

		return $form_data;
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
	 * Handle submission (log to DB).
	 *
	 * @param array $form_data The form data.
	 * @param mixed $arg2      Unused – required by interface.
	 * @return void
	 */
	public function on_submission( $form_data, $arg2 = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $form_data ) ) {
			return;
		}

		$attribution = $this->extract_stored_attribution( $form_data );
		if ( empty( $attribution ) ) {
			$attribution = $this->normalize_attribution_payload( $this->get_attribution_payload() );
		}

		if ( empty( $attribution ) ) {
			return;
		}

		$this->log_submission(
			'ninjaforms',
			$this->resolve_form_identifier( $form_data ),
			$attribution,
			$form_data
		);
	}

	/**
	 * Register the Ninja Forms submission extra handler.
	 *
	 * @param array $handlers Existing handlers.
	 * @return array
	 */
	public function register_extra_value_handler( $handlers ) {
		$handlers = is_array( $handlers ) ? $handlers : array();

		if ( class_exists( self::EXTRA_HANDLER_CLASS ) ) {
			$handlers[ Ninja_Forms_Submission_Extra_Handler::EXTRA_VALUE_KEY ] = self::EXTRA_HANDLER_CLASS;
		}

		return $handlers;
	}

	/**
	 * Enqueue JS for DataLayer events.
	 */
	public function enqueue_ninja_js() {
		if ( ! $this->should_populate() ) {
			return;
		}
		?>
		<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function() {
			if (typeof Backbone === 'undefined' || !window.Backbone.Radio) {
				return;
			}
			
			const formChannel = Backbone.Radio.channel('form');
			
			formChannel.on('form:submit:response', function(response) {
				window.dataLayer = window.dataLayer || [];
				window.dataLayer.push({
					event: 'ninja_form_submit',
					form_id: response.data.form_id,
					ct_attribution: (window.ClickTrail && window.ClickTrail.getData) ? window.ClickTrail.getData() : {}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Normalize attribution payloads to the ClickTrail canonical field set.
	 *
	 * @param mixed $payload Raw payload.
	 * @return array<string,string>
	 */
	private function normalize_attribution_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$payload        = Attribution_Provider::sanitize( $payload );
		$allowed_keys   = array_fill_keys( Attribution_Provider::get_field_mapping(), true );
		$legacy_aliases = Attribution_Provider::get_field_alias_mapping();
		$normalized     = array();

		foreach ( $payload as $key => $value ) {
			if ( isset( $legacy_aliases[ $key ] ) && $legacy_aliases[ $key ] !== $key ) {
				continue;
			}

			if ( ! isset( $allowed_keys[ $key ] ) ) {
				continue;
			}

			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				continue;
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * Extract stored attribution from Ninja Forms submission extra data.
	 *
	 * @param array $form_data Submission payload.
	 * @return array<string,string>
	 */
	private function extract_stored_attribution( $form_data ) {
		if ( empty( $form_data['extra'] ) || ! is_array( $form_data['extra'] ) ) {
			return array();
		}

		if ( empty( $form_data['extra'][ Ninja_Forms_Submission_Extra_Handler::EXTRA_VALUE_KEY ] ) ) {
			return array();
		}

		return $this->normalize_attribution_payload(
			$form_data['extra'][ Ninja_Forms_Submission_Extra_Handler::EXTRA_VALUE_KEY ]
		);
	}

	/**
	 * Resolve the Ninja Forms form ID from the submission payload only.
	 *
	 * @param array $form_data Submission payload.
	 * @return int|string
	 */
	private function resolve_form_identifier( $form_data ) {
		$candidates = array(
			$form_data['form_id'] ?? null,
			$form_data['id'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}

			$value = trim( (string) $candidate );
			if ( '' === $value ) {
				continue;
			}

			return ctype_digit( $value ) ? (int) $value : sanitize_text_field( $value );
		}

		return 0;
	}
}
