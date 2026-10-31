<?php
/**
 * Identity_Resolver phone normalisation (E.164 before SHA-256), issue #144.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'CLICUTCL_DIR' ) ) {
		define( 'CLICUTCL_DIR', dirname( __DIR__, 2 ) . '/' );
	}

	require_once dirname( __DIR__, 2 ) . '/includes/Core/Storage/class-option-cache.php';
	require_once dirname( __DIR__, 2 ) . '/includes/support/class-feature-registry.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-consentdecisioninterface.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-consent-decision.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-identityresolverinterface.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-phone-calling-codes.php';
	require_once dirname( __DIR__, 2 ) . '/includes/tracking/class-identity-resolver.php';

	use CLICUTCL\Tracking\Identity_Resolver;
	use CLICUTCL\Tracking\Phone_Calling_Codes;
	use PHPUnit\Framework\TestCase;

	final class IdentityResolverPhoneTest extends TestCase {
		protected function setUp(): void {
			Identity_Resolver::reset_drop_counts();
		}

		protected function tearDown(): void {
			unset( $GLOBALS['clicktrail_test_filters'] );
			Identity_Resolver::reset_drop_counts();
		}

		private function resolve_phone( string $phone, array $extra_input = array() ): array {
			$resolver = new Identity_Resolver();

			return $resolver->resolve(
				array_merge( array( 'phone' => $phone ), $extra_input ),
				array( 'marketing_allowed' => true )
			);
		}

		private function with_default_country( ?string $country ): void {
			$GLOBALS['clicktrail_test_filters']['clicutcl_identity_default_country'] = static function () use ( $country ) {
				return $country;
			};
		}

		public function test_international_format_with_spaces_hashes_e164(): void {
			$this->with_default_country( null );
			$out = $this->resolve_phone( '+351 912 345 678' );
			$this->assertSame( hash( 'sha256', '+351912345678' ), $out['hashed_phone'] );
		}

		public function test_double_zero_prefix_becomes_plus(): void {
			$this->with_default_country( null );
			$out = $this->resolve_phone( '00351912345678' );
			$this->assertSame( hash( 'sha256', '+351912345678' ), $out['hashed_phone'] );
		}

		public function test_national_number_gets_filtered_default_country(): void {
			$this->with_default_country( 'PT' );
			$out = $this->resolve_phone( '912345678' );
			$this->assertSame( hash( 'sha256', '+351912345678' ), $out['hashed_phone'] );
		}

		public function test_national_number_falls_back_to_event_country(): void {
			$this->with_default_country( null );
			$out = $this->resolve_phone( '912345678', array( 'country' => 'pt' ) );
			$this->assertSame( hash( 'sha256', '+351912345678' ), $out['hashed_phone'] );
		}

		public function test_national_number_without_default_country_is_dropped_and_counted(): void {
			$this->with_default_country( null );
			$out = $this->resolve_phone( '912345678' );
			$this->assertArrayNotHasKey( 'hashed_phone', $out );
			$this->assertSame( array( 'phone_no_country' => 1 ), Identity_Resolver::get_drop_counts() );
		}

		public function test_invalid_after_normalisation_is_dropped_and_counted(): void {
			$this->with_default_country( null );
			$this->assertArrayNotHasKey( 'hashed_phone', $this->resolve_phone( '+0351912345678' ) );
			$this->assertArrayNotHasKey( 'hashed_phone', $this->resolve_phone( '+35191' ) );
			$this->assertArrayNotHasKey( 'hashed_phone', $this->resolve_phone( '+3519123456789012345' ) );
			$this->assertSame( array( 'phone_invalid' => 3 ), Identity_Resolver::get_drop_counts() );
		}

		public function test_unknown_default_country_is_dropped(): void {
			$this->with_default_country( 'XX' );
			$this->assertArrayNotHasKey( 'hashed_phone', $this->resolve_phone( '912345678' ) );
		}

		public function test_empty_phone_is_not_counted_as_drop(): void {
			$this->with_default_country( null );
			$this->assertArrayNotHasKey( 'hashed_phone', $this->resolve_phone( '' ) );
			$this->assertSame( array(), Identity_Resolver::get_drop_counts() );
		}

		public function test_email_path_unchanged(): void {
			$this->with_default_country( null );
			$out = $this->resolve_phone( '912345678', array( 'email' => ' Buyer@Example.com ' ) );
			$this->assertSame( hash( 'sha256', 'buyer@example.com' ), $out['hashed_email'] );
		}

		public function test_calling_code_table_covers_iso_list(): void {
			$this->assertSame( '351', Phone_Calling_Codes::for_country( 'pt' ) );
			$this->assertSame( '1', Phone_Calling_Codes::for_country( 'US' ) );
			$this->assertSame( '', Phone_Calling_Codes::for_country( 'XX' ) );
			$this->assertSame( '', Phone_Calling_Codes::for_country( '' ) );
		}
	}
}
