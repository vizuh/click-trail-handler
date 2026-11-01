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
		$GLOBALS['ct_cf7_hooks'][ $tag ][] = array( $callback, $accepted_args );
		return true;
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/Core/class-attribution-provider.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/interface-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-abstract-form-adapter.php';
	require_once dirname( __DIR__, 2 ) . '/includes/integrations/forms/class-cf7-adapter.php';

	class WPCF7_Submission {
		public static $instance;
		public $status = 'init';
		public $posted = array();

		public static function get_instance() {
			return self::$instance;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_posted_data() {
			return $this->posted;
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
			// Landing-page render: the field exists but the cookie did not yet.
			$submission->posted = array( 'ct_ft_source' => '' );
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
				->with( 'contact-form-7', 17, array( 'ft_source' => 'google' ), array( 'ct_ft_source' => 'google' ) )
				->willReturnCallback(
					function () use ( $submission ) {
						$this->assertSame( 'mail_sent', $submission->get_status(), 'Conversion logged before final success.' );
					}
				);
			$adapter->register_hooks();
			// CF7 filters wpcf7_posted_data while building the submission, before any outcome hook.
			$submission->posted = $this->filter( 'wpcf7_posted_data', $submission->posted );
			if ( $reaches_mail ) {
				// Another pre-send callback may abort after ClickTrail's callback.
				$this->fire( 'wpcf7_before_send_mail', array( $form, false, $submission ) );
			}
			$submission->status = $status;
			$this->fire( 'wpcf7_' . $status, array( $form ) );
		}

		public function test_submit_time_cookie_wins_over_hidden_fields_rendered_one_touch_behind(): void {
			$submission                 = new WPCF7_Submission();
			$submission->status         = 'mail_sent';
			$submission->posted         = array(
				'ct_lt_campaign'                   => 'google_first_visit',
				'ct_gclid'                         => 'GA',
				'ct_fbclid'                        => '',
				'ct_attribution_selected_click_id' => 'GA',
			);
			WPCF7_Submission::$instance = $submission;
			$GLOBALS['ct_cf7_hooks']    = array();
			$form = new class() {
				public function id() {
					return 17;
				}
			};
			$adapter = $this->getMockBuilder( \CLICUTCL\Integrations\Forms\CF7_Adapter::class )
				->onlyMethods( array( 'log_submission', 'get_attribution_payload' ) )->getMock();
			$adapter->method( 'get_attribution_payload' )->willReturn(
				array(
					'lt_campaign'                   => 'meta_this_visit',
					'fbclid'                        => 'FB',
					'attribution_selected_click_id' => 'FB',
				)
			);
			$adapter->expects( $this->once() )->method( 'log_submission' )->with(
				'contact-form-7',
				17,
				array(
					'lt_campaign'                   => 'meta_this_visit',
					'attribution_selected_click_id' => 'FB',
					'fbclid'                        => 'FB',
				),
				$this->anything()
			);
			$adapter->register_hooks();
			$submission->posted = $this->filter( 'wpcf7_posted_data', $submission->posted );
			$this->fire( 'wpcf7_before_send_mail', array( $form, false, $submission ) );
			$this->fire( 'wpcf7_mail_sent', array( $form ) );
		}

		private function zero_field_adapter( array $payload, bool $log_expected ) {
			$submission                 = new WPCF7_Submission();
			$submission->status         = 'mail_sent';
			$submission->posted         = array( 'your-name' => 'Jonas' );
			WPCF7_Submission::$instance = $submission;
			$GLOBALS['ct_cf7_hooks']    = array();
			$adapter                    = $this->getMockBuilder( \CLICUTCL\Integrations\Forms\CF7_Adapter::class )
				->onlyMethods( array( 'log_submission', 'get_attribution_payload', 'refresh_allowed' ) )->getMock();
			$adapter->method( 'get_attribution_payload' )->willReturn( $payload );
			$adapter->method( 'refresh_allowed' )->willReturn( $log_expected );
			$adapter->expects( $log_expected ? $this->once() : $this->never() )->method( 'log_submission' )
				->with( 'contact-form-7', 17, $payload, array( 'your-name' => 'Jonas' ) );
			$adapter->register_hooks();
			return $adapter;
		}

		public function test_form_without_ct_fields_logs_the_cookie_payload(): void {
			$form = new class() {
				public function id() {
					return 17;
				}
			};
			$this->zero_field_adapter( array( 'gclid' => 'G1', 'lt_source' => 'google' ), true );
			$this->fire( 'wpcf7_mail_sent', array( $form ) );
		}

		public function test_form_without_ct_fields_logs_nothing_without_consent(): void {
			$form = new class() {
				public function id() {
					return 17;
				}
			};
			$this->zero_field_adapter( array( 'gclid' => 'G1' ), false );
			$this->fire( 'wpcf7_mail_sent', array( $form ) );
		}

		public function test_partial_reference_form_logs_the_full_cookie_payload(): void {
			$payload = array(
				'gclid' => 'G1', 'fbclid' => 'F1', 'ft_source' => 'a', 'ft_medium' => 'b', 'lt_source' => 'c',
				'lt_medium' => 'd', 'lt_campaign' => 'e', 'attribution_selected_click_id' => 'G1',
			);
			$submission                 = new WPCF7_Submission();
			$submission->status         = 'mail_sent';
			$submission->posted         = array( 'ct_gclid' => 'G1', 'ct_lt_campaign' => 'e' );
			WPCF7_Submission::$instance = $submission;
			$GLOBALS['ct_cf7_hooks']    = array();
			$form = new class() {
				public function id() {
					return 17;
				}
			};
			foreach ( array( true, false ) as $allowed ) {
				$adapter = $this->getMockBuilder( \CLICUTCL\Integrations\Forms\CF7_Adapter::class )
					->onlyMethods( array( 'log_submission', 'get_attribution_payload', 'refresh_allowed' ) )->getMock();
				$adapter->method( 'get_attribution_payload' )->willReturn( $payload );
				$adapter->method( 'refresh_allowed' )->willReturn( $allowed );
				$adapter->expects( $allowed ? $this->once() : $this->never() )->method( 'log_submission' )
					->with( 'contact-form-7', 17, $payload, $this->anything() );
				$adapter->on_submission( $form );
			}
		}

		private function fire( string $hook, array $args ): void {
			foreach ( $GLOBALS['ct_cf7_hooks'][ $hook ] ?? array() as $registration ) {
				call_user_func_array( $registration[0], array_slice( $args, 0, $registration[1] ) );
			}
		}

		private function filter( string $hook, $value ) {
			foreach ( $GLOBALS['ct_cf7_hooks'][ $hook ] ?? array() as $registration ) {
				$value = call_user_func( $registration[0], $value );
			}
			return $value;
		}
	}
}
