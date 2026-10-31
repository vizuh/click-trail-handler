<?php
/**
 * Fluent Forms submission-meta regression test.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-fluent-forms-adapter.php';

use CLICUTCL\Integrations\Forms\Fluent_Forms_Adapter;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return '2026-08-27 20:00:00';
	}
}

if ( ! function_exists( 'wpFluent' ) ) {
	function wpFluent() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Fluent Forms public API name.
		return $GLOBALS['clicktrail_test_fluent_db'];
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

final class ClickTrailFluentDbStub {
	public array $rows = array();
	private string $table = '';

	public function table( string $table ): self {
		$this->table = $table;
		return $this;
	}

	public function insert( array $row ): bool {
		$this->rows[] = array( 'table' => $this->table, 'row' => $row );
		return true;
	}
}

final class ClickTrailTestableFluentFormsAdapter extends Fluent_Forms_Adapter {
	protected function log_submission( $platform, $form_id, $attribution, $identity_input = array(), $entry_id = null ) {
		// Persistence is the behavior under test; ClickTrail event logging is covered separately.
		return array();
	}
}

final class ClickTrailHiddenFieldTestAdapter extends Fluent_Forms_Adapter {
	protected function should_populate() {
		return true;
	}

	protected function get_attribution_payload() {
		return array(
			'ft_source' => 'release-smoke',
			'lt_source' => 'release-smoke',
		);
	}
}

final class FluentFormsAdapterTest extends TestCase {
	public function test_hidden_fields_are_emitted_once_for_dual_fluent_render_hooks(): void {
		$adapter = new ClickTrailHiddenFieldTestAdapter();
		$form    = (object) array( 'id' => 7 );
		ob_start();
		$adapter->add_hidden_fields( $form );
		$adapter->add_hidden_fields( $form );
		$adapter->add_hidden_fields( (object) array( 'id' => 8 ) );
		$output = (string) ob_get_clean();

		$this->assertSame( 2, substr_count( $output, 'name="ct_ft_source"' ) );
		$this->assertSame( 2, substr_count( $output, 'name="ct_lt_source"' ) );
	}

	public function test_hidden_fields_render_empty_values_by_default_and_the_cookie_with_the_filter(): void {
		$adapter = new ClickTrailHiddenFieldTestAdapter();
		ob_start();
		$adapter->add_hidden_fields( (object) array( 'id' => 21 ) );
		$default = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="hidden" name="ct_ft_source" value="">', $default );
		$this->assertStringNotContainsString( 'release-smoke', $default );

		$GLOBALS['clicktrail_test_filters']['clicutcl_render_server_attribution_values'] = static fn() => true;
		ob_start();
		$adapter->add_hidden_fields( (object) array( 'id' => 22 ) );
		$opted_in = (string) ob_get_clean();
		unset( $GLOBALS['clicktrail_test_filters']['clicutcl_render_server_attribution_values'] );

		$this->assertStringContainsString( 'name="ct_ft_source" value="release-smoke"', $opted_in );
	}

	public function test_submission_meta_uses_fluent_forms_response_id_column(): void {
		$db = new ClickTrailFluentDbStub();
		$GLOBALS['clicktrail_test_fluent_db'] = $db;

		$adapter = new ClickTrailTestableFluentFormsAdapter();
		$adapter->on_submission(
			42,
			array(
				'ct_ft_source'   => 'release-smoke',
				'ct_lt_source'   => 'release-smoke',
				'ct_fill_source' => 'js',
			),
			(object) array( 'id' => 7 )
		);

		$this->assertCount( 2, $db->rows );
		foreach ( $db->rows as $insert ) {
			$this->assertSame( 'fluentform_submission_meta', $insert['table'] );
			$this->assertSame( 42, $insert['row']['response_id'] );
			$this->assertArrayNotHasKey( 'submission_id', $insert['row'] );
		}
	}

	private function registered_hooks_with_fluent_version( ?string $version ): array {
		require_once dirname( __DIR__ ) . '/helpers/hook-recorder-forms-namespace.php';
		if ( null !== $version ) {
			define( 'FLUENTFORM_VERSION', $version );
		}
		$GLOBALS['ct_forms_registered_hooks'] = array();
		( new Fluent_Forms_Adapter() )->register_hooks();
		$hooks = $GLOBALS['ct_forms_registered_hooks'];
		sort( $hooks );
		return $hooks;
	}

	private const SLASH_HOOKS = array(
		'fluentform/form_element_start',
		'fluentform/insert_response_data',
		'fluentform/submission_inserted',
	);

	private const LEGACY_HOOKS = array(
		'fluentform_form_element_start',
		'fluentform_insert_response_data',
		'fluentform_submission_inserted',
	);

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fluent_5_and_later_registers_only_the_slash_hooks(): void {
		$expected = self::SLASH_HOOKS;
		sort( $expected );
		$this->assertSame( $expected, $this->registered_hooks_with_fluent_version( '6.2.16' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fluent_5_0_0_is_the_first_slash_hook_version(): void {
		$expected = self::SLASH_HOOKS;
		sort( $expected );
		$this->assertSame( $expected, $this->registered_hooks_with_fluent_version( '5.0.0' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fluent_before_5_registers_only_the_legacy_hooks(): void {
		$expected = self::LEGACY_HOOKS;
		sort( $expected );
		$this->assertSame( $expected, $this->registered_hooks_with_fluent_version( '4.3.25' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unknown_fluent_version_registers_only_the_slash_hooks(): void {
		$expected = self::SLASH_HOOKS;
		sort( $expected );
		$this->assertSame( $expected, $this->registered_hooks_with_fluent_version( null ) );
	}
}
