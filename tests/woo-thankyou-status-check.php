<?php
/**
 * The thank-you hook must not report a failed or cancelled order as a purchase or mark it sent.
 * Runs in its own PHP process (see tests/unit/WooThankyouStatusTest.php).
 *
 * @package ClickTrail
 */
namespace CLICUTCL\Server_Side {
	class Dispatcher {
		public static $captured;
		public static function dispatch_commerce_event( $p ) { self::$captured = $p; return Adapter_Result::success( 200, 'ok' ); }
		public static function get_adapter_key() { return 'generic'; }
	}
}
namespace {
	require dirname( __DIR__ ) . '/vendor/autoload.php';
	require dirname( __DIR__ ) . '/tests/bootstrap.php'; define( 'CLICUTCL_DIR', dirname( __DIR__ ) . '/' ); require_once dirname( __DIR__ ) . '/includes/class-autoloader.php'; \CLICUTCL\Autoloader::run();
	require_once dirname( __DIR__ ) . '/tests/helpers/consent-mode-settings-stub.php';
	if ( ! function_exists( 'wp_doing_cron' ) ) { function wp_doing_cron() { return false; } }
	function wp_parse_args($a,$d=array()){return array_merge($d,(array)$a);} function current_time( $t ) { return '2026-10-16 00:00:00'; }
	function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
	function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
	function get_userdata( $id ) { return false; }
	if ( ! class_exists( 'WC_Order' ) ) {
		class WC_Order {
			public $meta = array();
			function get_id(){return 42;} function get_order_number(){return '42';} function get_total(){return '100.00';}
			function get_currency(){return 'EUR';} function get_items(){return array();} function get_coupon_codes(){return array();}
			public $status='failed'; function get_status(){return $this->status;} function get_billing_email(){return 'buyer@example.com';} function get_billing_phone(){return '+15555550100';}
			function get_customer_ip_address(){return '';} function get_customer_user_agent(){return '';}
			function get_meta($k,$s=true){return $this->meta[$k] ?? '';} function update_meta_data($k,$v){$this->meta[$k]=$v;}
			function get_meta_data(){ $o=array(); foreach($this->meta as $k=>$v){ $o[]=(object)array('key'=>$k,'value'=>$v);} return $o; }
			function save(){}
		}
	}
	
	$GLOBALS['clicktrail_test_consent_mode_enabled'] = false; // consent not required (default install)
	function wc_get_order( $id ) { return $GLOBALS['order']; }
	function is_ssl(){return true;}
	$GLOBALS['order'] = $order = new WC_Order();
	$_COOKIE = array();
	// Customer's card is declined: WooCommerce redirects to order-received; templates/checkout/thankyou.php
	// shows "Unfortunately your order cannot be processed" but still fires do_action('woocommerce_thankyou').
	$failures = array();
	foreach ( array( 'failed', 'cancelled' ) as $status ) {
		$order->status = $status;
		$order->meta   = array();
		\CLICUTCL\Server_Side\Dispatcher::$captured = null;
		ob_start(); ( new \CLICUTCL\Integrations\WooCommerce() )->push_purchase_event( 42 ); $out = ob_get_clean();
		if ( '' !== trim( $out ) || null !== \CLICUTCL\Server_Side\Dispatcher::$captured || isset( $order->meta['_clicutcl_tracking_sent'] ) ) {
			$failures[] = "$status order produced a purchase or a sent marker";
		}
	}

	// The buyer pays again from the pay link: exactly one purchase, then the marker.
	$order->status = 'processing';
	ob_start(); ( new \CLICUTCL\Integrations\WooCommerce() )->push_purchase_event( 42 ); $out = ob_get_clean();
	if ( false === strpos( $out, '"purchase"' ) || 'purchase' !== ( \CLICUTCL\Server_Side\Dispatcher::$captured['event_name'] ?? '' ) || 'yes' !== ( $order->meta['_clicutcl_tracking_sent'] ?? '' ) ) {
		$failures[] = 'paid order did not produce one purchase with a sent marker';
	}

	if ( $failures ) {
		echo implode( "\n", $failures ), "\n";
		exit( 1 );
	}
	echo "woo thankyou status checks passed\n";
}
