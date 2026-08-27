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
	protected function log_submission( $platform, $form_id, $attribution, $identity_input = array() ) {
		// Persistence is the behavior under test; ClickTrail event logging is covered separately.
	}
}

final class FluentFormsAdapterTest extends TestCase {
	public function test_submission_meta_uses_fluent_forms_response_id_column(): void {
		$db = new ClickTrailFluentDbStub();
		$GLOBALS['clicktrail_test_fluent_db'] = $db;

		$adapter = new ClickTrailTestableFluentFormsAdapter();
		$adapter->on_submission(
			42,
			array(
				'ct_ft_source' => 'release-smoke',
				'ct_lt_source' => 'release-smoke',
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
}
