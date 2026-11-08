<?php
/**
 * Cookie scope resolver, settings sanitisation and new-install seed.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-cookie-scope.php';
	require_once dirname( __DIR__, 2 ) . '/includes/database/class-installer.php';
	require_once dirname( __DIR__, 2 ) . '/includes/admin/class-admin.php';

	use CLICUTCL\Admin\Admin;
	use CLICUTCL\Core\Cookie_Scope;
	use CLICUTCL\Database\Installer;
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( 'add_settings_error' ) ) {
		function add_settings_error( $setting, $code, $message, $type = 'error' ) {
			$GLOBALS['clicktrail_test_settings_errors'][] = $code;
		}
	}
	if ( ! function_exists( 'is_multisite' ) ) {
		function is_multisite() {
			return (bool) ( $GLOBALS['clicktrail_test_is_multisite'] ?? false );
		}
	}
	if ( ! function_exists( 'is_subdomain_install' ) ) {
		function is_subdomain_install() {
			return (bool) ( $GLOBALS['clicktrail_test_subdomain_install'] ?? false );
		}
	}

	final class CookieScopeSettingsTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['clicktrail_test_current_user_can'] = true;
			$GLOBALS['clicktrail_test_home_url']         = 'https://www.example.com';
		}

		protected function tearDown(): void {
			unset(
				$GLOBALS['clicktrail_test_filters'],
				$GLOBALS['clicktrail_test_options'],
				$GLOBALS['clicktrail_test_home_url'],
				$GLOBALS['clicktrail_test_settings_errors'],
				$GLOBALS['clicktrail_test_is_multisite'],
				$GLOBALS['clicktrail_test_subdomain_install'],
				$GLOBALS['clicktrail_test_current_user_can']
			);
		}

		private function admin(): Admin {
			return ( new \ReflectionClass( Admin::class ) )->newInstanceWithoutConstructor();
		}

		/**
		 * @return array<string, array{0:string,1:string,2:string,3:string,4:string}>
		 */
		public static function resolver_table(): array {
			return array(
				'www to apex'            => array( 'registrable', '', 'www.example.com', 'example.com', 'ok' ),
				'apex stays'             => array( 'registrable', '', 'example.com', 'example.com', 'ok' ),
				'deep subdomain'         => array( 'registrable', '', 'a.b.example.com', 'example.com', 'ok' ),
				'three label ccTLD'      => array( 'registrable', '', 'www.brand.co.uk', 'brand.co.uk', 'ok' ),
				'bare co.uk'             => array( 'registrable', '', 'co.uk', '', 'public_suffix' ),
				'github.io tenant'       => array( 'registrable', '', 'team.github.io', '', 'public_suffix' ),
				'ipv4'                   => array( 'registrable', '', '127.0.0.1', '', 'ip_or_single_label' ),
				'ipv6'                   => array( 'registrable', '', '[::1]', '', 'ip_or_single_label' ),
				'localhost'              => array( 'registrable', '', 'localhost', '', 'ip_or_single_label' ),
				'host scope'             => array( 'host', '', 'www.example.com', '', 'host_scope' ),
				'unknown scope'          => array( 'bogus', '', 'www.example.com', '', 'host_scope' ),
				'custom parent'          => array( 'custom', 'example.com', 'www.example.com', 'example.com', 'ok' ),
				'custom leading dot'     => array( 'custom', '.Example.com', 'www.example.com', 'example.com', 'ok' ),
				'custom unrelated'       => array( 'custom', 'other.com', 'www.example.com', '', 'custom_not_suffix' ),
				'custom lookalike'       => array( 'custom', 'ample.com', 'www.example.com', '', 'custom_not_suffix' ),
				'custom public suffix'   => array( 'custom', 'github.io', 'team.github.io', '', 'public_suffix' ),
				'custom ip host'         => array( 'custom', '10.0.0.5', '10.0.0.5', '', 'ip_or_single_label' ),
			);
		}

		/**
		 * @dataProvider resolver_table
		 */
		public function test_resolver_table( string $scope, string $custom, string $host, string $domain, string $reason ): void {
			$this->assertSame(
				array(
					'domain' => $domain,
					'reason' => $reason,
				),
				Cookie_Scope::resolve( $scope, $custom, $host )
			);
		}

		public function test_public_suffix_filter_extends_the_table(): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_public_suffixes'] = static function ( array $suffixes ): array {
				$suffixes[] = 'example.org';
				return $suffixes;
			};

			$this->assertSame(
				array(
					'domain' => '',
					'reason' => 'public_suffix',
				),
				Cookie_Scope::resolve( 'registrable', '', 'a.example.org' )
			);
		}

		public function test_schema_default_is_host_for_existing_settings(): void {
			$GLOBALS['clicktrail_test_options']['clicutcl_attribution_settings'] = array( 'cookie_days' => 30 );

			$settings = $this->admin()->sanitize_settings( array( 'cookie_days' => 45 ) );

			$this->assertSame( 'host', $settings['cookie_scope'] );
			$this->assertSame( '', $settings['cookie_custom_domain'] );
			$this->assertSame( '', Cookie_Scope::resolve_for_site( $settings )['domain'] );
			$this->assertSame( '', Cookie_Scope::resolve_for_site( array( 'cookie_days' => 30 ) )['domain'] );
		}

		public function test_custom_domain_that_is_a_parent_is_kept(): void {
			$settings = $this->admin()->sanitize_settings(
				array(
					'cookie_scope'         => 'custom',
					'cookie_custom_domain' => ' .Example.COM ',
				)
			);

			$this->assertSame( 'custom', $settings['cookie_scope'] );
			$this->assertSame( 'example.com', $settings['cookie_custom_domain'] );
			$this->assertSame( 'example.com', Cookie_Scope::resolve_for_site( $settings )['domain'] );
		}

		public function test_custom_domain_outside_the_site_falls_back_to_host_with_error(): void {
			$settings = $this->admin()->sanitize_settings(
				array(
					'cookie_scope'         => 'custom',
					'cookie_custom_domain' => 'other.com',
				)
			);

			$this->assertSame( 'host', $settings['cookie_scope'] );
			$this->assertSame( '', Cookie_Scope::resolve_for_site( $settings )['domain'] );
			$this->assertContains( 'clicutcl_cookie_custom_domain_invalid', $GLOBALS['clicktrail_test_settings_errors'] );
		}

		public function test_cookie_reissue_is_off_for_existing_settings_and_sanitised_as_a_toggle(): void {
			$GLOBALS['clicktrail_test_options']['clicutcl_attribution_settings'] = array( 'cookie_days' => 30 );

			$this->assertSame( 0, $this->admin()->sanitize_settings( array( 'cookie_days' => 45 ) )['attribution_cookie_reissue'] );
			$this->assertSame( 1, $this->admin()->sanitize_settings( array( 'attribution_cookie_reissue' => '1' ) )['attribution_cookie_reissue'] );
			$this->assertSame( 0, $this->admin()->sanitize_settings( array( 'attribution_cookie_reissue' => '0' ) )['attribution_cookie_reissue'] );
		}

		public function test_invalid_scope_value_is_sanitised_to_host(): void {
			$settings = $this->admin()->sanitize_settings( array( 'cookie_scope' => 'everywhere' ) );

			$this->assertSame( 'host', $settings['cookie_scope'] );
		}

		public function test_new_install_is_seeded_registrable(): void {
			Installer::seed_fresh_install_defaults();

			$this->assertSame(
				array(
					'cookie_scope'               => 'registrable',
					'attribution_cookie_reissue' => 1,
				),
				$GLOBALS['clicktrail_test_options']['clicutcl_attribution_settings']
			);
		}

		public function test_subdomain_multisite_is_seeded_host(): void {
			$GLOBALS['clicktrail_test_is_multisite']     = true;
			$GLOBALS['clicktrail_test_subdomain_install'] = true;

			Installer::seed_fresh_install_defaults();

			$this->assertSame( 'host', $GLOBALS['clicktrail_test_options']['clicutcl_attribution_settings']['cookie_scope'] );
		}

		/**
		 * @return array<string, array{0:string}>
		 */
		public static function earlier_use_signals(): array {
			return array(
				'setup wizard completed' => array( 'clicutcl_setup_complete' ),
				'tracking settings'      => array( 'clicutcl_tracking_v2' ),
				'db version'             => array( 'clicutcl_db_version' ),
				'wizard mode'            => array( 'clicutcl_mode' ),
			);
		}

		/**
		 * @dataProvider earlier_use_signals
		 */
		public function test_reactivating_an_existing_install_is_not_seeded( string $option ): void {
			$GLOBALS['clicktrail_test_options'][ $option ] = 1;

			Installer::seed_fresh_install_defaults();

			$this->assertArrayNotHasKey( 'clicutcl_attribution_settings', $GLOBALS['clicktrail_test_options'] );
		}

		public function test_extended_public_suffix_table(): void {
			foreach ( array( 'a.gob.mx', 'a.go.jp', 'a.ltd.uk', 'a.plc.uk', 'a.nhs.uk', 'a.sch.uk', 'app.workers.dev', 'x.onrender.com', 'x.fly.dev', 'x.ngrok-free.app', 'x.ngrok.io' ) as $host ) {
				$this->assertSame( 'public_suffix', Cookie_Scope::resolve( 'registrable', '', $host )['reason'], $host );
			}
			foreach ( array( 'org.uk', 'gov.uk', 'ac.uk', 'me.uk', 'com.mx', 'com.ar', 'com.co', 'co.nz', 'co.za', 'co.in', 'co.il', 'com.tr', 'com.cn', 'com.hk', 'com.sg', 'com.my', 'com.tw' ) as $suffix ) {
				$this->assertTrue( Cookie_Scope::is_public_suffix( $suffix ), $suffix );
			}
		}

		public function test_every_hosting_platform_suffix_still_resolves_to_host_only(): void {
			$suffixes = array( 'workers.dev', 'pages.dev', 'onrender.com', 'fly.dev', 'herokuapp.com', 'netlify.app', 'vercel.app', 'github.io', 'gitlab.io', 'ngrok.io', 'ngrok-free.app', 'wpengine.com', 'wpenginepowered.com', 'kinsta.cloud', 'cloudfront.net', 'azurewebsites.net', 'amazonaws.com', 'web.app', 'firebaseapp.com', 'appspot.com', 'myshopify.com', 'blogspot.com', 'wordpress.com' );
			foreach ( $suffixes as $suffix ) {
				$this->assertTrue( Cookie_Scope::is_public_suffix( $suffix ), $suffix );
				$this->assertSame( 'public_suffix', Cookie_Scope::resolve( 'registrable', '', 'tenant.' . $suffix )['reason'], 'registrable on tenant.' . $suffix );
				$this->assertSame( 'public_suffix', Cookie_Scope::resolve( 'custom', $suffix, 'tenant.' . $suffix )['reason'], 'custom ' . $suffix );
				$this->assertSame( '', Cookie_Scope::resolve( 'registrable', '', 'tenant.' . $suffix )['domain'], $suffix );
			}
		}

		public function test_the_source_holds_no_full_hosting_hostname_literal(): void {
			// Plugin Check's offloaded-content scan reads a quoted service hostname as a fetched resource.
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Core/class-cookie-scope.php' );
			foreach ( array( 'herokuapp.com', 'amazonaws.com', 'cloudfront.net', 'azurewebsites.net', 'github.io' ) as $host ) {
				$this->assertStringNotContainsString( "'" . $host . "'", $source, $host );
			}
		}

		public function test_existing_settings_are_never_reseeded(): void {
			$GLOBALS['clicktrail_test_options']['clicutcl_attribution_settings'] = array( 'cookie_days' => 30 );

			Installer::seed_fresh_install_defaults();

			$this->assertSame( array( 'cookie_days' => 30 ), $GLOBALS['clicktrail_test_options']['clicutcl_attribution_settings'] );
		}
	}
}
