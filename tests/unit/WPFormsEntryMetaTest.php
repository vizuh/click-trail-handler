<?php
/**
 * WPForms entry-meta write: WPForms exposes entry_meta through __get() with no
 * __isset(), so an isset() guard never let ClickTrail write entry meta.
 *
 * @package ClickTrail
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-wpforms-adapter.php';

use CLICUTCL\Integrations\Forms\WPForms_Adapter;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wpforms' ) ) {
	function wpforms() {
		return $GLOBALS['clicktrail_test_wpforms'];
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}

/**
 * Same shape as WPForms\WPForms: private registry, public __get(), no __isset().
 */
final class ClickTrailWPFormsShape {
	private array $registry;

	public function __construct( $entry_meta ) {
		$this->registry = array( 'entry_meta' => $entry_meta );
	}

	public function __get( $name ) {
		return $this->registry[ $name ] ?? null;
	}
}

final class ClickTrailEntryMetaSpy {
	public array $added = array();

	public function add( array $row ) {
		$this->added[] = $row;
		return 1;
	}
}

final class ClickTrailTestableWPFormsAdapter extends WPForms_Adapter {
	protected function should_populate() {
		return true;
	}

	protected function get_attribution_payload() {
		return array( 'ft_source' => 'google' );
	}

	protected function log_submission( $platform, $form_id, $attribution, $identity_input = array() ) {
	}
}

final class WPFormsEntryMetaTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_attribution_is_written_to_entry_meta_through_magic_getter(): void {
		require_once dirname( __DIR__ ) . '/helpers/consent-mode-settings-stub.php';
		foreach ( array( 'class-decision-v1.php', 'class-resolver-v1.php', 'class-snapshot-v1.php' ) as $file ) {
			require_once dirname( __DIR__, 2 ) . '/includes/Consent/' . $file;
		}
		require_once dirname( __DIR__, 2 ) . '/includes/server-side/class-consent.php';

		$spy                                = new ClickTrailEntryMetaSpy();
		$GLOBALS['clicktrail_test_wpforms'] = new ClickTrailWPFormsShape( $spy );

		( new ClickTrailTestableWPFormsAdapter() )->on_submission( array(), array(), array( 'id' => 7 ), 21 );

		$this->assertCount( 1, $spy->added );
		$this->assertSame( 'ct_ft_source', $spy->added[0]['type'] );
		$this->assertSame( 21, $spy->added[0]['entry_id'] );
		$this->assertSame( 'google', $spy->added[0]['data'] );
	}
}
