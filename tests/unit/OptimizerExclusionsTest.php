<?php
/**
 * Optimizer exclusions: every ClickTrail script tag carries the vendor opt-out
 * attributes exactly once, on every site, and the vendor PHP filters receive
 * the four ClickTrail entries (#153).
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions, Squiz.Commenting

namespace CLICUTCL\Integrations {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ct_optimizer_filters'][ $tag ][] = array( $callback, $priority, $accepted_args );
		return true;
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/class-optimizer-compat.php';

	use CLICUTCL\Integrations\Optimizer_Compat;
	use PHPUnit\Framework\TestCase;

	final class OptimizerExclusionsTest extends TestCase {

		private const HANDLES = array(
			'clicutcl-consent-bridge-js' => false,
			'clicutcl-attribution-js'    => true,
			'clicutcl-consent-js'        => false,
			'clicutcl-events-js'         => true,
		);

		private const ATTRIBUTE_STRINGS = array(
			'nowprocket',
			'data-no-optimize="1"',
			'data-no-defer="1"',
			'data-noptimize="1"',
			'data-cfasync="false"',
		);

		private const PATHS = array(
			'plugins/click-trail-handler/assets/js/clicutcl-consent-bridge.js',
			'plugins/click-trail-handler/assets/js/clicutcl-attribution.js',
			'plugins/click-trail-handler/assets/js/clicutcl-consent.js',
			'plugins/click-trail-handler/assets/js/clicutcl-events.js',
		);

		private const FILES = array(
			'clicutcl-consent-bridge.js',
			'clicutcl-attribution.js',
			'clicutcl-consent.js',
			'clicutcl-events.js',
		);

		protected function setUp(): void {
			$GLOBALS['ct_optimizer_filters'] = array();
		}

		/**
		 * Mirrors `WP_Scripts::do_item` on WordPress 6.5+: `wp_get_script_tag()`
		 * applies `wp_script_attributes` to the keyed array, then the full string
		 * goes through `script_loader_tag`.
		 */
		private function render( string $handle, bool $defer, array $extra_attr = array(), string $after = '' ): string {
			$attr = array(
				'src' => 'https://example.test/wp-content/plugins/click-trail-handler/assets/js/' . substr( $handle, 0, -3 ) . '.js?ver=1.11.0',
				'id'  => $handle . '-js',
			);
			if ( $defer ) {
				$attr['defer']            = true;
				$attr['data-wp-strategy'] = 'defer';
			}
			$attr = Optimizer_Compat::add_attributes( $extra_attr + $attr );

			$tag = '<script';
			foreach ( $attr as $name => $value ) {
				$tag .= true === $value ? ' ' . $name : ' ' . $name . '="' . $value . '"';
			}
			$tag .= "></script>\n" . $after;

			return Optimizer_Compat::mark_tag( $tag, $handle );
		}

		private function assertAttributesOnce( string $tag, string $handle ): void {
			foreach ( self::ATTRIBUTE_STRINGS as $attribute ) {
				$this->assertSame( 1, substr_count( $tag, ' ' . $attribute ), "$attribute once on $handle: $tag" );
			}
			$this->assertStringNotContainsString( 'data-no-minify', $tag );
			$this->assertSame( 1, substr_count( $tag, '<script' ), "one script token on $handle" );
			$this->assertStringContainsString( 'id="' . $handle . '-js"', $tag );
		}

		private function assertEveryHandleCovered(): void {
			foreach ( self::HANDLES as $handle => $defer ) {
				$once = $this->render( $handle, $defer );
				$this->assertAttributesOnce( $once, $handle );
				$this->assertSame( $once, Optimizer_Compat::mark_tag( $once, $handle ), "second script_loader_tag pass is a no-op for $handle" );
				if ( $defer ) {
					$this->assertStringContainsString( ' defer data-wp-strategy="defer"', $once );
				}
			}
		}

		public function test_every_handle_carries_each_attribute_once_without_gravity_forms(): void {
			$this->assertFalse( class_exists( 'GFForms', false ), 'this case runs before the GFForms stub is declared' );
			$this->assertEveryHandleCovered();
		}

		public function test_every_handle_carries_each_attribute_once_with_gravity_forms(): void {
			if ( ! class_exists( 'GFForms', false ) ) {
				eval( 'class GFForms {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- stub the Gravity Forms marker class for this case only.
			}
			$this->assertTrue( class_exists( 'GFForms', false ) );
			$this->assertEveryHandleCovered();
		}

		public function test_theme_added_attribute_is_not_duplicated(): void {
			$tag = $this->render( 'clicutcl-consent-js', false, array( 'data-cfasync' => 'false', 'nowprocket' => true ) );
			$this->assertAttributesOnce( $tag, 'clicutcl-consent-js' );
		}

		public function test_string_fallback_adds_missing_attributes_to_the_src_tag_only(): void {
			$after = "<script id=\"clicutcl-events-js-after\">window.ctAfter=1;</script>\n";
			$tag   = '<script src="https://example.test/x/clicutcl-events.js" id="clicutcl-events-js" defer data-wp-strategy="defer" data-noptimize="1"></script>' . "\n" . $after;

			$marked = Optimizer_Compat::mark_tag( $tag, 'clicutcl-events-js' );

			foreach ( self::ATTRIBUTE_STRINGS as $attribute ) {
				$this->assertSame( 1, substr_count( $marked, ' ' . $attribute ), $attribute );
			}
			$this->assertStringEndsWith( $after, $marked, 'inline after-script untouched' );
			$this->assertStringStartsWith( '<script nowprocket data-no-optimize="1" data-no-defer="1" data-cfasync="false" src=', $marked );
			$this->assertSame( $marked, Optimizer_Compat::mark_tag( $marked, 'clicutcl-events-js' ) );
		}

		public function test_string_fallback_without_id_targets_the_src_token(): void {
			$tag    = "<script src=\"https://example.test/x/clicutcl-consent.js\"></script>\n";
			$marked = Optimizer_Compat::mark_tag( $tag, 'clicutcl-consent-js' );
			$this->assertSame( '<script nowprocket data-no-optimize="1" data-no-defer="1" data-noptimize="1" data-cfasync="false" src="https://example.test/x/clicutcl-consent.js"></script>' . "\n", $marked );
		}

		public function test_foreign_handle_is_untouched(): void {
			$tag  = "<script src=\"https://example.test/wp-includes/js/jquery/jquery.min.js\" id=\"jquery-core-js\"></script>\n";
			$attr = array(
				'src' => 'https://example.test/wp-includes/js/jquery/jquery.min.js',
				'id'  => 'jquery-core-js',
			);

			$this->assertSame( $tag, Optimizer_Compat::mark_tag( $tag, 'jquery-core' ) );
			$this->assertSame( $attr, Optimizer_Compat::add_attributes( $attr ) );
			$this->assertSame( array(), Optimizer_Compat::add_attributes( array() ) );
		}

		public function test_nitropack_marker_survives_alongside_the_set(): void {
			$tag    = "<script data-nitropack-exclude=\"true\" src=\"https://example.test/x/clicutcl-attribution.js\" id=\"clicutcl-attribution-js\" defer data-wp-strategy=\"defer\"></script>\n";
			$marked = Optimizer_Compat::mark_tag( $tag, 'clicutcl-attribution-js' );
			$this->assertSame( 1, substr_count( $marked, 'data-nitropack-exclude="true"' ) );
			foreach ( self::ATTRIBUTE_STRINGS as $attribute ) {
				$this->assertSame( 1, substr_count( $marked, ' ' . $attribute ), $attribute );
			}
		}

		public function test_path_callbacks_add_four_paths_without_duplicates(): void {
			$this->assertSame( self::PATHS, Optimizer_Compat::exclude_paths( array() ) );
			$this->assertSame( array_merge( array( 'foo.js' ), self::PATHS ), Optimizer_Compat::exclude_paths( array( 'foo.js' ) ) );
			$this->assertSame( self::PATHS, Optimizer_Compat::exclude_paths( self::PATHS ) );
		}

		public function test_file_callbacks_add_four_file_names_without_duplicates(): void {
			$this->assertSame( self::FILES, Optimizer_Compat::exclude_files( array() ) );
			$this->assertSame( self::FILES, Optimizer_Compat::exclude_files( self::FILES ) );
		}

		public function test_handle_callbacks_add_four_handles_without_duplicates(): void {
			$handles = array_keys( self::HANDLES );
			$this->assertSame( $handles, Optimizer_Compat::exclude_handles( array() ) );
			$this->assertSame( array_merge( array( 'jquery-core' ), $handles ), Optimizer_Compat::exclude_handles( array( 'jquery-core' ) ) );
			$this->assertSame( $handles, Optimizer_Compat::exclude_handles( $handles ) );
		}

		public function test_csv_callback_adds_four_file_names_without_duplicates(): void {
			$csv = implode( ',', self::FILES );
			$this->assertSame( $csv, Optimizer_Compat::exclude_csv( '' ) );
			$this->assertSame( 'seal.js,' . $csv, Optimizer_Compat::exclude_csv( 'seal.js' ) );
			$this->assertSame( 'seal.js,' . $csv, Optimizer_Compat::exclude_csv( 'seal.js,' . $csv ) );
			$this->assertSame( $csv, Optimizer_Compat::exclude_csv( 'clicutcl-consent-bridge.js, clicutcl-attribution.js,clicutcl-consent.js , clicutcl-events.js' ) );
		}

		public function test_register_hooks_tag_filters_and_only_verified_vendor_filters(): void {
			Optimizer_Compat::register();
			$registered = $GLOBALS['ct_optimizer_filters'];

			$this->assertSame( array( array( Optimizer_Compat::class, 'add_attributes' ), 10, 1 ), $registered['wp_script_attributes'][0] );
			$this->assertSame( array( array( Optimizer_Compat::class, 'mark_tag' ), 10, 2 ), $registered['script_loader_tag'][0] );

			$expected = array(
				'rocket_delay_js_exclusions'       => 'exclude_paths',
				'rocket_exclude_defer_js'          => 'exclude_paths',
				'rocket_exclude_js'                => 'exclude_paths',
				'litespeed_optm_js_exc'            => 'exclude_paths',
				'litespeed_optm_js_defer_exc'      => 'exclude_paths',
				'litespeed_optm_gm_js_exc'         => 'exclude_paths',
				'autoptimize_filter_js_exclude'    => 'exclude_csv',
				'perfmatters_delay_js_exclusions'  => 'exclude_files',
				'perfmatters_defer_js_exclusions'  => 'exclude_files',
				'perfmatters_minify_js_exclusions' => 'exclude_files',
				'sgo_js_minify_exclude'            => 'exclude_handles',
				'sgo_javascript_combine_exclude'   => 'exclude_handles',
				'sgo_js_async_exclude'             => 'exclude_handles',
			);
			foreach ( $expected as $filter => $method ) {
				$this->assertSame( array( Optimizer_Compat::class, $method ), $registered[ $filter ][0][0], $filter );
			}

			$vendor = array_diff_key( $registered, array( 'wp_script_attributes' => 1, 'script_loader_tag' => 1 ) );
			$this->assertSame( array_keys( $expected ), array_keys( $vendor ), 'no unverified vendor filter (FlyingPress has none)' );
		}
	}
}
