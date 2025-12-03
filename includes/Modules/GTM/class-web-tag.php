<?php
/**
 * Class ClickTrail\Modules\GTM\Web_Tag
 *
 * @package   ClickTrail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace ClickTrail\Modules\GTM;

use ClickTrail\Core\Context;

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
			add_action( 'wp_head', array( $this, 'render' ) );
			add_action( 'wp_body_open', array( $this, 'render_no_js' ), -9999 );
			add_action( 'wp_footer', array( $this, 'render_no_js' ) ); // Fallback
		}
	}

	/**
	 * Outputs Tag Manager script.
	 */
	public function render() {
		$container_id = $this->gtm_settings->get_container_id();
		if ( empty( $container_id ) ) {
			return;
		}

		$script = "
			(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
			new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
			j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
			'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
			})(window,document,'script','dataLayer','%s');
		";

		printf( "\n<!-- %s -->\n", esc_html__( 'Google Tag Manager snippet added by ClickTrail', 'click-trail-handler' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is hardcoded script with safely escaped ID.
		printf( "<script>%s</script>", sprintf( $script, esc_js( $container_id ) ) );
		printf( "\n<!-- %s -->\n", esc_html__( 'End Google Tag Manager snippet added by ClickTrail', 'click-trail-handler' ) );
	}

	/**
	 * Outputs Tag Manager iframe for when the browser has JavaScript disabled.
	 */
	public function render_no_js() {
		// Prevent double rendering if wp_body_open triggered and footer also runs
		if ( defined( 'CLICKTRAIL_GTM_NOSCRIPT_RENDERED' ) ) {
			return;
		}
		define( 'CLICKTRAIL_GTM_NOSCRIPT_RENDERED', true );

		$container_id = $this->gtm_settings->get_container_id();
		if ( empty( $container_id ) ) {
			return;
		}

		$iframe_src = 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $container_id );

		?>
		<!-- <?php esc_html_e( 'Google Tag Manager (noscript) snippet added by ClickTrail', 'click-trail-handler' ); ?> -->
		<noscript>
			<iframe src="<?php echo esc_url( $iframe_src ); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe>
		</noscript>
		<!-- <?php esc_html_e( 'End Google Tag Manager (noscript) snippet added by ClickTrail', 'click-trail-handler' ); ?> -->
		<?php
	}
}
