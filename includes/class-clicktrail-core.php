<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 */
class ClickTrail_Core {

	/**
	 * Plugin context.
	 *
	 * @var ClickTrail\Core\Context
	 */
	protected $context;

	/**
	 * Consent Mode module.
	 *
	 * @var ClickTrail\Modules\Consent_Mode\Consent_Mode
	 */
	protected $consent_mode;

	/**
	 * GTM module.
	 *
	 * @var ClickTrail\Modules\GTM\Web_Tag
	 */
	protected $gtm;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->context = new ClickTrail\Core\Context( CLICKTRAIL_PLUGIN_MAIN_FILE );
		
		// Initialize Modules
		$this->consent_mode = new ClickTrail\Modules\Consent_Mode\Consent_Mode( $this->context );
		$this->gtm          = new ClickTrail\Modules\GTM\Web_Tag( $this->context );

		$this->register_cpt();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}
			wp_send_json_success( array( 'post_id' => $post_id ) );
		} else {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}
	}
}
