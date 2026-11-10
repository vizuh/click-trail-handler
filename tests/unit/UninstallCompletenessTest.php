<?php
/**
 * Uninstall removes every option and transient the plugin writes, on every site of a network.
 *
 * The option names come from the plugin's own writers: every `update_option()`, `add_option()`,
 * `*_site_option()`, `set_transient()`, `Claim_Lock::acquire()` and direct `INSERT IGNORE` into the
 * options table under `includes/` and `clicutcl.php`. Each name is seeded into an in-memory options
 * table that `get_option()`/`delete_option()` and `$wpdb` share, uninstall.php runs, and no seeded
 * row may remain. A writer whose name the scan cannot resolve fails the test until it is listed in
 * DYNAMIC_WRITERS with the names it produces.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	use PHPUnit\Framework\TestCase;

	/**
	 * Options table of the current blog; rows live in `$GLOBALS['clicktrail_test_options']`, the store
	 * the bootstrap option stubs use.
	 */
	final class Uninstall_Fake_Wpdb {
		public string $prefix  = 'wp_';
		public string $options = 'wp_options';
		/** @var string[] */
		public array $dropped = array();

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( string $query, ...$args ): array {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return array(
				'sql'  => $query,
				'args' => $args,
			);
		}

		private static function like( string $name, string $pattern ): bool {
			$regex = '';
			$len   = strlen( $pattern );
			for ( $i = 0; $i < $len; $i++ ) {
				$c = $pattern[ $i ];
				if ( '\\' === $c && $i + 1 < $len ) {
					$regex .= preg_quote( $pattern[ ++$i ], '/' );
				} elseif ( '%' === $c ) {
					$regex .= '.*';
				} elseif ( '_' === $c ) {
					$regex .= '.';
				} else {
					$regex .= preg_quote( $c, '/' );
				}
			}
			return 1 === preg_match( '/^' . $regex . '$/s', $name );
		}

		public function get_var( $q ) {
			return null;
		}

		public function query( $q ) {
			if ( is_string( $q ) && 0 === strpos( $q, 'DROP TABLE IF EXISTS ' ) ) {
				$this->dropped[] = trim( substr( $q, strlen( 'DROP TABLE IF EXISTS ' ) ) );
				return true;
			}
			if ( ! is_array( $q ) || 0 !== strpos( $q['sql'], "DELETE FROM {$this->options} WHERE option_name LIKE" ) ) {
				return 0;
			}
			$deleted = 0;
			foreach ( array_keys( $GLOBALS['clicktrail_test_options'] ) as $name ) {
				foreach ( $q['args'] as $pattern ) {
					if ( self::like( (string) $name, (string) $pattern ) ) {
						unset( $GLOBALS['clicktrail_test_options'][ $name ] );
						++$deleted;
						break;
					}
				}
			}
			return $deleted;
		}
	}

	/**
	 * Class UninstallCompletenessTest
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class UninstallCompletenessTest extends TestCase {
		/**
		 * Writers whose option name is built at runtime, keyed `<file relative to repo>:<argument>`,
		 * with the names they produce. `option:` rows are plain options, `transient:` rows transients.
		 */
		private const DYNAMIC_WRITERS = array(
			// counter_bump() receives ip_counter_key() / visitor_counter_key().
			'includes/database/class-whatsapp-ref-store.php:$key' => array( 'transient:clicutcl_wa_ip_x', 'transient:clicutcl_wa_vis_x' ),
			// Claim_Lock::insert_row() writes the keys its acquire() callers pass; the scan seeds those.
			'includes/tracking/class-claim-lock.php:$key'         => array(),
			// build_key() prefixes KEY_PREFIX.
			'includes/tracking/class-dedup-store.php:$key'        => array( 'transient:clicutcl_v2_dup_x' ),
		);

		private Uninstall_Fake_Wpdb $db;

		protected function setUp(): void {
			$this->db                                     = new Uninstall_Fake_Wpdb();
			$GLOBALS['wpdb']                              = $this->db;
			$GLOBALS['clicktrail_test_options']           = array();
			$GLOBALS['clicktrail_test_site_options']      = array();
			$GLOBALS['clicktrail_test_blogs']             = array();
			$GLOBALS['clicktrail_test_blog_stack']        = array();
			$GLOBALS['clicktrail_test_blog_id']           = 1;
			$GLOBALS['clicktrail_test_cleared_hooks']     = array();
			$GLOBALS['clicktrail_test_is_multisite']      = false;
			$GLOBALS['clicktrail_test_filters']           = array();

			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'click-trail-handler/clicutcl.php' );
			}
			if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
				function wp_clear_scheduled_hook( $hook ) {
					$GLOBALS['clicktrail_test_cleared_hooks'][] = $GLOBALS['clicktrail_test_blog_id'] . ':' . $hook;
					return 0;
				}
			}
			if ( ! function_exists( 'is_multisite' ) ) {
				function is_multisite() {
					return (bool) ( $GLOBALS['clicktrail_test_is_multisite'] ?? false );
				}
			}
			if ( ! function_exists( 'delete_site_option' ) ) {
				function delete_site_option( $option ) {
					if ( ! is_multisite() ) {
						return delete_option( $option );
					}
					$found = isset( $GLOBALS['clicktrail_test_site_options'][ $option ] );
					unset( $GLOBALS['clicktrail_test_site_options'][ $option ] );
					return $found;
				}
			}
			if ( ! function_exists( 'get_sites' ) ) {
				function get_sites( $args = array() ) {
					$ids = array_keys( $GLOBALS['clicktrail_test_blogs'] );
					// Core caps get_sites() at 100 unless 'number' is 0, so uninstall must ask for all.
					return ( isset( $args['number'] ) && 0 === $args['number'] ) ? $ids : array_slice( $ids, 0, 1 );
				}
			}
			if ( ! function_exists( 'switch_to_blog' ) ) {
				function switch_to_blog( $blog_id ) {
					$from                                           = $GLOBALS['clicktrail_test_blog_id'];
					$GLOBALS['clicktrail_test_blogs'][ $from ]      = $GLOBALS['clicktrail_test_options'];
					$GLOBALS['clicktrail_test_blog_stack'][]        = $from;
					clicktrail_test_load_blog( (int) $blog_id );
					return true;
				}
			}
			if ( ! function_exists( 'restore_current_blog' ) ) {
				function restore_current_blog() {
					$current                                       = $GLOBALS['clicktrail_test_blog_id'];
					$GLOBALS['clicktrail_test_blogs'][ $current ]  = $GLOBALS['clicktrail_test_options'];
					clicktrail_test_load_blog( (int) array_pop( $GLOBALS['clicktrail_test_blog_stack'] ) );
					return true;
				}
			}
			if ( ! function_exists( 'clicktrail_test_load_blog' ) ) {
				function clicktrail_test_load_blog( int $blog_id ): void {
					$GLOBALS['clicktrail_test_blog_id']  = $blog_id;
					$GLOBALS['clicktrail_test_options']  = $GLOBALS['clicktrail_test_blogs'][ $blog_id ] ?? array();
					$GLOBALS['wpdb']->prefix             = 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
					$GLOBALS['wpdb']->options            = $GLOBALS['wpdb']->prefix . 'options';
				}
			}
		}

		/**
		 * Option names written by the plugin, from its own source.
		 *
		 * @return array{options: string[], site_options: string[], transients: string[], unresolved: string[]}
		 */
		private static function written_names(): array {
			$root  = dirname( __DIR__, 2 );
			$files = array( $root . '/clicutcl.php' );
			$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iter as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
			sort( $files );

			// Pass 1: class constants (string and array of strings) and parents.
			$consts  = array();
			$parents = array();
			$classes = array();
			foreach ( $files as $file ) {
				$src   = (string) file_get_contents( $file );
				$class = preg_match( '/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)(?:\s+extends\s+\\\\?(?:\w+\\\\)*(\w+))?/m', $src, $m ) ? $m[1] : '';
				if ( '' !== $class && ! empty( $m[2] ) ) {
					$parents[ $class ][] = $m[2];
				}
				$classes[ $file ] = $class;
				preg_match_all( "/const\s+([A-Z0-9_]+)\s*=\s*'([^']*)'/", $src, $found, PREG_SET_ORDER );
				foreach ( $found as $c ) {
					$consts[ $class ][ $c[1] ][] = $c[2];
				}
				preg_match_all( '/const\s+([A-Z0-9_]+)\s*=\s*array\(([^)]*)\)/', $src, $found, PREG_SET_ORDER );
				foreach ( $found as $c ) {
					preg_match_all( "/'([^']*)'/", $c[2], $items );
					$consts[ $class ][ $c[1] ] = $items[1];
				}
			}

			$resolve_const = static function ( string $class, string $name, bool $late ) use ( $consts, $parents ): array {
				$values = $consts[ $class ][ $name ] ?? array();
				if ( $late ) {
					foreach ( $parents as $child => $list ) {
						if ( in_array( $class, $list, true ) ) {
							$values = array_merge( $values, $consts[ $child ][ $name ] ?? array() );
						}
					}
				}
				if ( array() === $values ) { // Trait or unknown owner: any class declaring it.
					foreach ( $consts as $declared ) {
						$values = array_merge( $values, $declared[ $name ] ?? array() );
					}
				}
				return array_values( array_filter( $values, 'strlen' ) );
			};

			// Any first argument: shapes $resolve does not understand are reported as unresolved.
			$arg = '[^\s),][^,]*';

			$resolve = static function ( string $expr, string $class, string $src, int $offset ) use ( &$resolve, $resolve_const ): ?array {
				$expr = trim( $expr );
				if ( preg_match( "/^'([^']*)'\s*(\.)?(.*)$/s", $expr, $m ) && ( '' !== $m[2] || '' === trim( $m[3] ) ) ) {
					return array( $m[1] . ( '' !== $m[2] ? 'x' : '' ) );
				}
				if ( preg_match( '/^\\\\?(?:\w+\\\\)*(\w+)::([A-Z0-9_]+)\b(?!\s*\()\s*(\.)?(.*)$/s', $expr, $m ) && ( '' !== $m[3] || '' === trim( $m[4] ) ) ) {
					$owner  = in_array( $m[1], array( 'self', 'static' ), true ) ? $class : $m[1];
					$names  = $resolve_const( $owner, $m[2], 'static' === $m[1] );
					$suffix = '' !== $m[3] ? 'x' : '';
					return array() === $names ? null : array_map( static fn( $n ) => $n . $suffix, $names );
				}
				if ( preg_match( '/^\$(\w+)$/', $expr, $m ) ) {
					$before = substr( $src, 0, $offset );
					$var    = preg_quote( $m[1], '/' );
					$assign = preg_match_all( '/\$' . $var . '\s*=(?![=>])\s*([^;]+);/', $before, $a, PREG_OFFSET_CAPTURE ) ? end( $a[1] ) : null;
					$loop   = preg_match_all( '/foreach\s*\(\s*(\\\\?(?:\w+\\\\)*\w+::[A-Z0-9_]+)\s+as\s+\$' . $var . '\s*\)/', $before, $l, PREG_OFFSET_CAPTURE ) ? end( $l[1] ) : null;
					$pick   = ( $loop && ( ! $assign || $loop[1] > $assign[1] ) ) ? $loop : $assign;
					return $pick ? $resolve( $pick[0], $class, $src, (int) $pick[1] ) : null;
				}
				return null;
			};

			$out = array(
				'options'      => array(),
				'site_options' => array(),
				'transients'   => array(),
				'unresolved'   => array(),
			);
			$calls = '/\b(update_option|add_option|update_site_option|add_site_option|set_transient|set_site_transient|Claim_Lock::acquire)\(\s*(' . $arg . ')/';
			$sql   = '/INSERT IGNORE INTO \{\$wpdb->options\}[^"]*",\s*(' . $arg . ')/';
			foreach ( $files as $file ) {
				$src = (string) file_get_contents( $file );
				$rel = substr( $file, strlen( $root ) + 1 );
				preg_match_all( $calls, $src, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
				preg_match_all( $sql, $src, $raw, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
				foreach ( $raw as $r ) {
					$hits[] = array( $r[0], array( 'insert_ignore', $r[0][1] ), $r[1] );
				}
				foreach ( $hits as $hit ) {
					$names = $resolve( $hit[2][0], $classes[ $file ], $src, (int) $hit[2][1] );
					if ( null === $names ) {
						$out['unresolved'][] = $rel . ':' . trim( $hit[2][0] );
						continue;
					}
					$kind = $hit[1][0];
					$bin  = 'set_transient' === $kind ? 'transients' : ( false !== strpos( $kind, 'site_' ) ? 'site_options' : 'options' );
					$out[ $bin ] = array_merge( $out[ $bin ], $names );
				}
			}

			foreach ( $out as $bin => $names ) {
				$out[ $bin ] = array_values( array_unique( $names ) );
				sort( $out[ $bin ] );
			}
			return $out;
		}

		/**
		 * Every row a fully used plugin leaves in one site's options table.
		 *
		 * @return array<string,string>
		 */
		private static function seeded_rows(): array {
			$written = self::written_names();
			$rows    = array(
				'blogname'          => 'Site',
				'other_plugin_lock' => 'x',
			);
			foreach ( array_merge( $written['options'], $written['site_options'] ) as $name ) {
				$rows[ $name ] = 'v';
			}
			$transients = $written['transients'];
			foreach ( self::DYNAMIC_WRITERS as $samples ) {
				foreach ( $samples as $sample ) {
					list( $bin, $name ) = explode( ':', $sample, 2 );
					if ( 'transient' === $bin ) {
						$transients[] = $name;
					} else {
						$rows[ $name ] = 'v';
					}
				}
			}
			foreach ( $transients as $name ) {
				$rows[ '_transient_' . $name ]         = 'v';
				$rows[ '_transient_timeout_' . $name ] = '9999999999';
			}
			return $rows;
		}

		/**
		 * Plugin rows left in a store.
		 *
		 * @param array<string,mixed> $rows Option rows.
		 * @return string[]
		 */
		private static function plugin_rows( array $rows ): array {
			return array_values(
				array_filter(
					array_keys( $rows ),
					static fn( $name ) => 1 === preg_match( '/^(_transient_(timeout_)?)?clicutcl_/', (string) $name )
				)
			);
		}

		private function run_uninstall( bool $preserve ): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_preserve_data_on_uninstall'] = static fn() => $preserve;
			require dirname( __DIR__, 2 ) . '/uninstall.php';
		}

		public function test_every_writer_resolves_to_a_known_name(): void {
			$written = self::written_names();

			$this->assertSame( array_keys( self::DYNAMIC_WRITERS ), $written['unresolved'], 'a new runtime-built option name needs a DYNAMIC_WRITERS entry' );
			foreach ( array( 'clicutcl_db_version', 'clicutcl_setup_complete', 'clicutcl_mode', 'clicutcl_queue_lock', 'clicutcl_tracking_v2', 'clicutcl_gtm', 'clicutcl_purchase_lock_x', 'clicutcl_form_claim_x' ) as $name ) {
				$this->assertContains( $name, $written['options'] );
			}
			$this->assertContains( 'clicutcl_server_side_network', $written['site_options'] );
			$this->assertContains( 'clicutcl_v2_rl_x', $written['transients'] );
		}

		public function test_single_site_uninstall_leaves_no_plugin_option(): void {
			$GLOBALS['clicktrail_test_options'] = self::seeded_rows();

			$this->run_uninstall( false );

			$this->assertSame( array(), self::plugin_rows( $GLOBALS['clicktrail_test_options'] ) );
			$this->assertSame( array( 'blogname', 'other_plugin_lock' ), array_keys( $GLOBALS['clicktrail_test_options'] ) );
			$this->assertContains( 'wp_clicutcl_queue', $this->db->dropped );
		}

		public function test_network_uninstall_cleans_every_site_and_the_network_options(): void {
			$GLOBALS['clicktrail_test_is_multisite']  = true;
			$GLOBALS['clicktrail_test_blogs']         = array(
				1 => self::seeded_rows(),
				2 => self::seeded_rows(),
			);
			$GLOBALS['clicktrail_test_options']       = $GLOBALS['clicktrail_test_blogs'][1];
			$GLOBALS['clicktrail_test_site_options']  = array(
				'clicutcl_server_side_network' => 'v',
				'site_name'                    => 'Network',
			);

			$this->run_uninstall( false );
			$GLOBALS['clicktrail_test_blogs'][ $GLOBALS['clicktrail_test_blog_id'] ] = $GLOBALS['clicktrail_test_options'];

			$this->assertSame( 1, $GLOBALS['clicktrail_test_blog_id'], 'uninstall restores the original blog' );
			foreach ( array( 1, 2 ) as $blog_id ) {
				$this->assertSame( array(), self::plugin_rows( $GLOBALS['clicktrail_test_blogs'][ $blog_id ] ), "site $blog_id" );
				$this->assertContains( $blog_id . ':clicutcl_daily_cleanup', $GLOBALS['clicktrail_test_cleared_hooks'] );
			}
			$this->assertSame( array( 'site_name' ), array_keys( $GLOBALS['clicktrail_test_site_options'] ) );
			$this->assertContains( 'wp_clicutcl_queue', $this->db->dropped );
			$this->assertContains( 'wp_2_clicutcl_queue', $this->db->dropped );
		}

		public function test_preserved_data_survives_uninstall_on_every_site(): void {
			$GLOBALS['clicktrail_test_is_multisite']  = true;
			$GLOBALS['clicktrail_test_blogs']         = array(
				1 => self::seeded_rows(),
				2 => self::seeded_rows(),
			);
			$GLOBALS['clicktrail_test_options']       = $GLOBALS['clicktrail_test_blogs'][1];
			$GLOBALS['clicktrail_test_site_options']  = array( 'clicutcl_server_side_network' => 'v' );
			$before                                   = $GLOBALS['clicktrail_test_blogs'];

			$this->run_uninstall( true );
			$GLOBALS['clicktrail_test_blogs'][ $GLOBALS['clicktrail_test_blog_id'] ] = $GLOBALS['clicktrail_test_options'];

			$this->assertSame( $before, $GLOBALS['clicktrail_test_blogs'] );
			$this->assertSame( array( 'clicutcl_server_side_network' ), array_keys( $GLOBALS['clicktrail_test_site_options'] ) );
			$this->assertSame( array(), $this->db->dropped );
		}
	}
}
