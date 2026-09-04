<?php
/**
 * CF7 outcome boundary; hook sequence follows CF7's submission lifecycle.
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions, Squiz.Commenting

namespace CLICUTCL\Integrations\Forms {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ct_cf7_hooks'][ $tag ][] = array( $callback, $accepted_args );
	}

	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-cf7-adapter.php';

	class WPCF7_Submission {
		public static $instance;
		public $status = 'init';

		public static function get_instance() {
			return self::$instance;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_posted_data() {
			return array();
		}
	}

	final class CF7SubmissionOutcomeTest extends \PHPUnit\Framework\TestCase {
		protected function tearDown(): void {
			unset( $GLOBALS['ct_cf7_hooks'] );
			WPCF7_Submission::$instance = null;
		}

		public static function outcomes(): array {
			return array(
				'validation' => array( 'validation_failed', false ),
				'acceptance' => array( 'acceptance_missing', false ),
				'spam'       => array( 'spam', false ),
				'abort'      => array( 'aborted', true ),
				'mail error' => array( 'mail_failed', true ),
				'success'    => array( 'mail_sent', true ),
			);
		}

		/**
		 * @dataProvider outcomes
		 */
		public function test_only_completed_success_reaches_conversion_logger( string $status, bool $reaches_mail ): void {
			$submission = new WPCF7_Submission();
			WPCF7_Submission::$instance = $submission;
			$GLOBALS['ct_cf7_hooks']    = array();
			$form = new class() {
				public function id() {
					return 17;
				}
			};
			$adapter = $this->getMockBuilder( \CLICUTCL\Integrations\Forms\CF7_Adapter::class )
				->onlyMethods( array( 'log_submission', 'get_attribution_payload' ) )->getMock();
			$adapter->method( 'get_attribution_payload' )->willReturn( array( 'ft_source' => 'google' ) );
			$adapter->expects( 'mail_sent' === $status ? $this->once() : $this->never() )
				->method( 'log_submission' )
				->with( 'contact-form-7', 17, array( 'ft_source' => 'google' ), array() )
				->willReturnCallback(
					function () use ( $submission ) {
						$this->assertSame( 'mail_sent', $submission->get_status(), 'Conversion logged before final success.' );
					}
				);
			$adapter->register_hooks();
			if ( $reaches_mail ) {
				// Another pre-send callback may abort after ClickTrail's callback.
				$this->fire( 'wpcf7_before_send_mail', array( $form, false, $submission ) );
			}
			$submission->status = $status;
			$this->fire( 'wpcf7_' . $status, array( $form ) );
		}

		private function fire( string $hook, array $args ): void {
			foreach ( $GLOBALS['ct_cf7_hooks'][ $hook ] ?? array() as $registration ) {
				call_user_func_array( $registration[0], array_slice( $args, 0, $registration[1] ) );
			}
		}
	}
}
