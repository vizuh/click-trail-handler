<?php
/**
 * Doubles for the delivery-resilience tests (circuit breaker, queue drain, retention purge).
 *
 * Required from inside a `@runInSeparateProcess` test method only: it declares namespaced
 * stand-ins (settings, dedup, touch-events store, privacy purgers) that other test files
 * declare differently, so it must never load into the shared PHPUnit process.
 *
 * `Delivery_Wpdb` is an in-memory `$wpdb`: an options table (the queue lock and the claim
 * rows), plus the `clicutcl_queue` table behind exactly the statements Queue, Dispatcher and
 * Cleanup issue. An unrecognised statement throws, so a changed query cannot pass silently.
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions, Squiz.Commenting, Generic.Files.OneObjectStructurePerFile, WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security

namespace CLICUTCL\Server_Side {
	class Settings {
		public static function get() {
			return $GLOBALS['ct_delivery_settings'] ?? array();
		}
	}
}

namespace CLICUTCL\Tracking {
	class Dedup_Store {
		public static function is_duplicate( $destination, $event_name, $event_id ) {
			return in_array( $event_id, $GLOBALS['ct_dedup_duplicates'] ?? array(), true );
		}
		public static function mark( ...$args ) {}
	}
}

namespace CLICUTCL\Database {
	class Installer {
		const DB_VERSION_OPTION = 'clicutcl_db_version';
		public static function run() {}
		public static function maybe_upgrade() {}
	}
	class Touch_Events_Store {
		public static function record( ...$args ) {}
	}
	class Whatsapp_Ref_Store {
		public static function purge_expired( ...$args ) {}
	}
}

namespace CLICUTCL\Settings {
	class Attribution_Settings {
		public function get_cookie_duration() {
			return 90;
		}
	}
}

namespace CLICUTCL\Privacy {
	class Woo_Order_Privacy {
		public function purge_expired_order_metadata( $days ) {
			return array();
		}
	}
	class Edd_Order_Privacy {
		public function purge_expired_order_metadata( $days ) {
			return array();
		}
	}
	class User_Registration_Privacy {
		public function purge_expired_user_metadata( $days ) {
			return array();
		}
	}
}

namespace CLICUTCL\Modules\Consent_Mode {
	/** Strict consent for queue workers; the live request itself needs none. */
	class Consent_Mode_Settings {
		public function is_consent_mode_enabled() {
			return (bool) ( $GLOBALS['ct_queue_consent_strict'] ?? false );
		}
		public function get_mode() {
			return 'strict';
		}
		public function is_consent_required_for_request() {
			return false;
		}
		public function get_cookie_name() {
			return 'ct_consent';
		}
	}
}

namespace {
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	function esc_url_raw( $url ) {
		return $url;
	}

	function esc_sql( $value ) {
		return $value;
	}

	function wp_get_environment_type() {
		return 'production';
	}

	function current_time( $type, $gmt = 0 ) {
		return gmdate( 'Y-m-d H:i:s' );
	}

	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['clicktrail_test_options'][ $option ] = $value;
		return true;
	}

	/**
	 * Stub transport. `ct_http['status']` is the response code; `delay_us` slows each send;
	 * `on_send` runs while the request is in flight; `throw` makes the transport blow up.
	 */
	function wp_remote_post( $url, $args ) {
		$http = &$GLOBALS['ct_http'];
		++$http['attempts'];
		$http['urls'][] = $url;
		if ( ! empty( $http['throw'] ) ) {
			throw new \RuntimeException( 'transport exploded' );
		}
		if ( ! empty( $http['delay_us'] ) ) {
			usleep( (int) $http['delay_us'] );
		}
		if ( is_callable( $http['on_send'] ?? null ) ) {
			( $http['on_send'] )( (int) $http['attempts'] );
		}
		return array( 'code' => $http['status'] );
	}

	function wp_remote_retrieve_response_code( $response ) {
		return $response['code'];
	}

	function wp_remote_retrieve_body( $response ) {
		return '';
	}

	final class Delivery_Wpdb {
		use Claim_Options_Wpdb {
			query as private options_query;
			get_var as private options_get_var;
		}

		public $prefix  = 'wp_';
		public $options = 'wp_options';

		/** @var array<int,array<string,mixed>> Queue rows by id. */
		public $rows = array();

		/** @var bool Whether the queue table has the v2 `status` column. */
		public $has_status = true;

		/** @var bool Make INSERTs fail, as a full or read-only database would. */
		public $fail_inserts = false;

		/** @var bool Make queue UPDATEs fail, so a processed row stays due. */
		public $fail_updates = false;

		private $next_id = 1;

		public function esc_like( $text ) {
			return $text;
		}

		public function insert( $table, $data, $format = null ) {
			if ( $this->fail_inserts ) {
				return false;
			}
			$row = $data + array(
				'status'     => 'pending',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);
			if ( ! $this->has_status ) {
				unset( $row['status'] );
			}
			$row['id']                = $this->next_id++;
			$this->rows[ $row['id'] ] = $row;
			return 1;
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$id = (int) $where['id'];
			if ( $this->fail_updates ) {
				return false;
			}
			if ( ! isset( $this->rows[ $id ] ) ) {
				return 0;
			}
			$this->rows[ $id ] = $data + $this->rows[ $id ];
			return 1;
		}

		public function delete( $table, $where, $format = null ) {
			if ( $table === $this->options ) {
				$name = $where['option_name'];
				if ( ! isset( $GLOBALS['clicktrail_test_options'][ $name ] ) || $GLOBALS['clicktrail_test_options'][ $name ] !== $where['option_value'] ) {
					return 0;
				}
				unset( $GLOBALS['clicktrail_test_options'][ $name ] );
				return 1;
			}
			$id = (int) $where['id'];
			if ( ! isset( $this->rows[ $id ] ) ) {
				return 0;
			}
			unset( $this->rows[ $id ] );
			return 1;
		}

		public function get_row( $q, $output = null ) {
			$this->assert_sql( $q, 'SELECT * FROM wp_clicutcl_queue WHERE event_name = %s AND event_id = %s' );
			foreach ( $this->rows as $row ) {
				if ( $row['event_name'] === $q['args'][0] && $row['event_id'] === $q['args'][1] ) {
					return $row;
				}
			}
			return null;
		}

		public function get_results( $q, $output = null ) {
			$sql = $q['sql'];
			if ( 0 === strpos( $sql, 'SELECT option_name, option_value FROM wp_options' ) ) {
				return array();
			}
			// Exactly the due-rows statement Queue::process() issues; anything else throws.
			if ( ! preg_match( "/^SELECT \\* FROM wp_clicutcl_queue WHERE (status = 'pending' AND )?next_attempt_at <= %s( AND id NOT IN \\(%d(, %d)*\\))? ORDER BY next_attempt_at ASC LIMIT (\\d+)$/", $sql, $m ) ) {
				throw new \RuntimeException( 'Unhandled query: ' . $sql );
			}
			$limit    = (int) end( $m );
			$now      = $q['args'][0];
			$excluded = array_map( 'intval', array_slice( $q['args'], 1 ) );
			if ( count( $excluded ) !== substr_count( $sql, '%d' ) ) {
				throw new \RuntimeException( 'Placeholder/argument mismatch: ' . $sql );
			}
			$due = array();
			foreach ( $this->rows as $row ) {
				if ( '' !== $m[1] && 'pending' !== ( $row['status'] ?? 'pending' ) ) {
					continue;
				}
				if ( in_array( (int) $row['id'], $excluded, true ) ) {
					continue;
				}
				if ( $row['next_attempt_at'] <= $now ) {
					$due[] = $row;
				}
			}
			usort(
				$due,
				static function ( $a, $b ) {
					return array( $a['next_attempt_at'], $a['id'] ) <=> array( $b['next_attempt_at'], $b['id'] );
				}
			);
			return array_slice( $due, 0, $limit );
		}

		public function get_var( $q ) {
			$sql = is_array( $q ) ? $q['sql'] : (string) $q;
			if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
				return $q['args'][0];
			}
			if ( 0 === strpos( $sql, 'SHOW COLUMNS' ) ) {
				return $this->has_status ? 'status' : null;
			}
			return $this->options_get_var( $q );
		}

		public function query( $q ) {
			$sql  = is_array( $q ) ? $q['sql'] : (string) $q;
			$args = is_array( $q ) ? $q['args'] : array();

			if ( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_options' ) ) {
				return $this->options_query( $q );
			}
			// Queue lock takeover: compare-and-swap on the exact stale value.
			if ( 0 === strpos( $sql, 'UPDATE wp_options SET option_value' ) ) {
				if ( ( $GLOBALS['clicktrail_test_options'][ $args[1] ] ?? null ) !== $args[2] ) {
					return 0;
				}
				$GLOBALS['clicktrail_test_options'][ $args[1] ] = $args[0];
				return 1;
			}
			if ( 0 === strpos( $sql, 'DELETE FROM wp_options WHERE option_name' ) ) {
				return $this->delete( 'wp_options', array( 'option_name' => $args[0], 'option_value' => $args[1] ) );
			}
			if ( 0 === strpos( $sql, 'DELETE FROM wp_clicutcl_queue' ) ) {
				return $this->delete_expired( $sql, $args );
			}
			if ( 0 === strpos( $sql, 'DELETE FROM wp_clicutcl_events' ) || 0 === strpos( $sql, 'DELETE FROM wp_clicutcl_touch_events' ) ) {
				return 0;
			}
			throw new \RuntimeException( 'Unhandled query: ' . $sql );
		}

		/**
		 * Retention DELETE: every `status <pred> AND created_at < ... INTERVAL %d DAY` clause is ORed;
		 * a statement without a status predicate is a plain age cut-off.
		 */
		private function delete_expired( string $sql, array $args ): int {
			$clauses = array();
			if ( preg_match_all( "/status (<>|=|NOT IN) ('[a-z]+'|\\([^)]*\\)) AND created_at < DATE_SUB\\(UTC_TIMESTAMP\\(\\), INTERVAL %d DAY\\)/", $sql, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $i => $match ) {
					$clauses[] = array( $match[1], $match[2], (int) $args[ $i ] );
				}
			} elseif ( false !== strpos( $sql, 'WHERE created_at < DATE_SUB' ) ) {
				$clauses[] = array( null, null, (int) $args[0] );
			} else {
				throw new \RuntimeException( 'Unhandled queue DELETE: ' . $sql );
			}

			$deleted = 0;
			foreach ( $this->rows as $id => $row ) {
				foreach ( $clauses as $clause ) {
					if ( $this->clause_matches( $row, $clause ) ) {
						unset( $this->rows[ $id ] );
						if ( ++$deleted >= 1000 ) {
							return $deleted;
						}
						break;
					}
				}
			}
			return $deleted;
		}

		private function clause_matches( array $row, array $clause ): bool {
			list( $operator, $operand, $days ) = $clause;
			if ( $row['created_at'] >= gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) {
				return false;
			}
			if ( null === $operator ) {
				return true;
			}
			$status = $row['status'] ?? 'pending';
			$values = array_map( 'trim', explode( ',', trim( $operand, "()'" ) ) );
			$values = array_map( static fn( $v ) => trim( $v, "'" ), $values );
			switch ( $operator ) {
				case '=':
					return $status === $values[0];
				case '<>':
					return $status !== $values[0];
				default:
					return ! in_array( $status, $values, true );
			}
		}

		private function assert_sql( $q, string $prefix ): void {
			if ( ! is_array( $q ) || 0 !== strpos( $q['sql'], $prefix ) ) {
				throw new \RuntimeException( 'Unhandled query: ' . wp_json_encode( $q ) );
			}
		}
	}

	/** Fresh world for one test: options, queue table, transport, settings. */
	function ct_delivery_boot(): Delivery_Wpdb {
		$GLOBALS['clicktrail_test_options']    = array(
			'clicutcl_db_version'                => 2,
			'clicutcl_queue_table_ready'         => 1,
			'clicutcl_queue_table_checked_at'    => time(),
		);
		$GLOBALS['clicktrail_test_transients'] = array();
		$GLOBALS['clicktrail_test_actions']    = array();
		$GLOBALS['clicktrail_test_filters']    = array();
		$GLOBALS['ct_dedup_duplicates']        = array();
		$GLOBALS['ct_delivery_settings']       = array(
			'enabled'      => true,
			'endpoint_url' => 'https://collector.test/a',
			'adapter'      => 'generic',
		);
		$GLOBALS['ct_http']                    = array(
			'attempts' => 0,
			'status'   => 503,
			'urls'     => array(),
		);
		$GLOBALS['wpdb']                       = new Delivery_Wpdb();
		return $GLOBALS['wpdb'];
	}

	function ct_delivery_event( string $id ): \CLICUTCL\Server_Side\Event {
		return new \CLICUTCL\Server_Side\Event(
			array(
				'event_name' => 'lead',
				'event_id'   => $id,
				'timestamp'  => time(),
				'source'     => 'web',
			)
		);
	}

	/** Insert a queue row that is due now (`age_days` old, `attempts` already spent). */
	function ct_queue_row( Delivery_Wpdb $wpdb, string $id, int $attempts = 0, int $age_days = 0, string $status = 'pending' ): int {
		$event = ct_delivery_event( $id );
		$wpdb->insert(
			'wp_clicutcl_queue',
			array(
				'event_name'      => 'lead',
				'event_id'        => $id,
				'adapter'         => 'generic',
				'endpoint'        => 'https://collector.test/a',
				'payload'         => wp_json_encode( $event->to_array() ),
				'attempts'        => $attempts,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ),
				'last_error'      => '',
				'status'          => $status,
				'created_at'      => gmdate( 'Y-m-d H:i:s', time() - $age_days * DAY_IN_SECONDS ),
			)
		);
		return (int) array_key_last( $wpdb->rows );
	}
}
