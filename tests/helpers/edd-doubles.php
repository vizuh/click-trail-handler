<?php
/**
 * Server-side doubles for the EDD integration test.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Server_Side {
	if ( ! class_exists( Dispatcher::class, false ) ) {
		/**
		 * Counting Dispatcher double; the real one needs the full server-side stack.
		 */
		class Dispatcher {
			/**
			 * Record a commerce dispatch and report success unless a test forces an error.
			 *
			 * @param array $payload Purchase payload.
			 * @return Adapter_Result
			 */
			public static function dispatch_commerce_event( $payload ) {
				$GLOBALS['clicktrail_test_dispatches'][] = $payload;
				if ( ! empty( $GLOBALS['clicktrail_test_dispatch_fails'] ) ) {
					return Adapter_Result::error( 500, 'collector_down' );
				}
				return Adapter_Result::success( 200, 'sent' );
			}

			/**
			 * Adapter key used in trace snapshots.
			 *
			 * @return string
			 */
			public static function get_adapter_key() {
				return 'generic';
			}
		}
	}
}

namespace CLICUTCL\Tracking {
	if ( ! class_exists( Identity_Resolver::class, false ) ) {
		/**
		 * Identity double: the receipt path only needs an array back.
		 */
		class Identity_Resolver {
			/**
			 * Return an empty identity payload.
			 *
			 * @param array $input   Identity input.
			 * @param array $options Resolver options.
			 * @return array
			 */
			public function resolve( array $input, array $options = array() ): array {
				$GLOBALS['clicktrail_test_identity_input'] = $input;
				return array();
			}
		}
	}
}
