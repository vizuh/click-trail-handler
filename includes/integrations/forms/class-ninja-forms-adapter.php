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

/**
 * Class Ninja_Forms_Adapter
 */
class Ninja_Forms_Adapter extends Abstract_Form_Adapter {

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
	}

	/**
	 * Inject attribution into submission data.
	 *
	 * @param array $form_data Form data.
	 * @return array
	 */
	public function inject_attribution( $form_data ) {
		if ( ! $this->should_populate() ) {
			return $form_data;
		}

		$payload = $this->get_attribution_payload();
		
		if ( empty( $payload ) ) {
			return $form_data;
		}

		if ( ! isset( $form_data['settings']['extra'] ) ) {
			// Ninja Forms stores extra data in settings usually? Or top level?
			// Documentation says $form_data['extra'] often used by integrations.
			// Let's try top level 'extra'.
			if ( ! isset( $form_data['extra'] ) ) {
				$form_data['extra'] = array();
			}
		}

		// Inject as a single block or individual keys?
		// Prompt suggested: $form_data['extra']['clicktrail_attribution'] = $payload;
		$form_data['extra']['clicktrail_attribution'] = $payload;

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
	 * @param mixed $form_id Unused in this hook signature usually, embedded in data.
	 */
	/**
	 * Handle submission (log to DB).
	 *
	 * @param array $arg1 The form data.
	 * @param mixed $arg2 Unused/Optional.
	 */
	public function on_submission( $arg1, $arg2 = null ) {
		$form_data = $arg1;
		$form_id = $arg2;
		if ( isset( $form_data['extra']['clicktrail_attribution'] ) ) {
			$attribution = $form_data['extra']['clicktrail_attribution'];
			$form_id     = isset( $form_data['form_id'] ) ? $form_data['form_id'] : ( isset( $form_data['id'] ) ? $form_data['id'] : 0 );
			
			$this->log_submission( 'ninjaforms', $form_id, $attribution );
		}
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
					ct_attribution: window.clickTrail || {}
				});
			});
		});
		</script>
		<?php
	}
}
