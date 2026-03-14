<?php

namespace CLICUTCL\Modules\GTM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CLICUTCL\Core\Context;

/**
 * Class ClickTrail\Modules\GTM\Web_Tag
 *
 * @package   ClickTrail
 */

/**
 * Class for Web tag.
 */
class Web_Tag {

	/**
	 * Context instance.
	 *
	 * @var Context
	 */
	protected $context;

	/**
	 * GTM_Settings instance.
	 *
	 * @var GTM_Settings
	 */
	protected $gtm_settings;

	/**
	 * Constructor.
	 *
	 * @param Context $context Plugin context.
	 */
	public function __construct( Context $context ) {
		$this->context      = $context;
		$this->gtm_settings = new GTM_Settings();
	}

	/**
	 * Registers tag hooks.
	 */
	public function register() {
		$this->gtm_settings->register();

		$container_id = $this->gtm_settings->get_container_id();

		if ( ! empty( $container_id ) ) {
			// GTM snippet must stay outside any consent gate and load as early as possible.
			add_action( 'wp_head', array( $this, 'render' ), 1 );
			add_action( 'wp_body_open', array( $this, 'render_no_js' ), 1 );
			add_action( 'wp_footer', array( $this, 'render_no_js' ), 1 ); // Fallback
		}
	}

	/**
	 * Outputs Tag Manager script.
	 */
	public function render() {
		$settings     = $this->gtm_settings->get();
		$container_id = $this->gtm_settings->get_container_id();
		$script_src   = GTM_Settings::build_script_src( $settings, $container_id );
		if ( empty( $container_id ) || empty( $script_src ) ) {
			return;
		}

		$script = "
			(function(w,d,s,l,u){w[l]=w[l]||[];w[l].push({'gtm.start':
			new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
			j=d.createElement(s);j.async=true;j.src=u;f.parentNode.insertBefore(j,f);
			})(window,document,'script','dataLayer','%s');
		";

		$mode_label = 'sgtm' === $this->gtm_settings->get_mode()
			? __( 'Google Tag Manager snippet added by ClickTrail (sGTM mode)', 'click-trail-handler' )
			: __( 'Google Tag Manager snippet added by ClickTrail', 'click-trail-handler' );
		printf( "\n<!-- %s -->\n", esc_html( $mode_label ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is hardcoded script with safely escaped ID.
		printf( "<script>%s</script>", sprintf( $script, esc_js( $script_src ) ) );
		printf( "\n<!-- %s -->\n", esc_html__( 'End Google Tag Manager snippet added by ClickTrail', 'click-trail-handler' ) );
	}

	/**
	 * Outputs Tag Manager iframe for when the browser has JavaScript disabled.
	 */
	public function render_no_js() {
		// Prevent double rendering if wp_body_open triggered and footer also runs
		if ( defined( 'CLICUTCL_GTM_NOSCRIPT_RENDERED' ) ) {
			return;
		}
		define( 'CLICUTCL_GTM_NOSCRIPT_RENDERED', true );

		$settings     = $this->gtm_settings->get();
		$container_id = $this->gtm_settings->get_container_id();
		$iframe_src   = GTM_Settings::build_noscript_src( $settings, $container_id );
		if ( empty( $container_id ) || empty( $iframe_src ) ) {
			return;
		}

		?>
		<!-- <?php esc_html_e( 'Google Tag Manager (noscript) snippet added by ClickTrail', 'click-trail-handler' ); ?> -->
		<noscript>
			<iframe src="<?php echo esc_url( $iframe_src ); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe>
		</noscript>
		<!-- <?php esc_html_e( 'End Google Tag Manager (noscript) snippet added by ClickTrail', 'click-trail-handler' ); ?> -->
		<?php
	}
}
