<?php
/**
 * Registered-user attribution metadata privacy lifecycle.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Privacy;

use CLICUTCL\Core\Attribution_Provider;
use CLICUTCL\Integrations\Ultimate_Member;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the ClickTrail user meta written when a user registers through Ultimate Member.
 *
 * Only the exact keys `Ultimate_Member` writes are exported, erased or purged (an allowlist built from the
 * attribution field mapping), never a prefix match. User meta is read and deleted through the core user
 * meta API, never through the table.
 *
 * Scope: only ClickTrail's own meta. Ultimate Member's own registration data (`submitted` details,
 * profile fields) is UM's to export and erase, and WordPress removes all of a user's meta when the
 * user is deleted.
 */
class User_Registration_Privacy {
	/** Retention and purge batch size. */
	public const BATCH_SIZE = 100;

	/**
	 * Every user meta key the registration integration writes: the attribution field mapping plus the sent marker.
	 *
	 * @return string[]
	 */
	public static function managed_keys(): array {
		$keys = array( Ultimate_Member::SENT_META_KEY );
		foreach ( Attribution_Provider::get_field_mapping() as $field ) {
			$key = Ultimate_Member::META_PREFIX . sanitize_key( (string) $field );
			if ( ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Whether a user meta key is one this integration writes.
	 *
	 * An exact allowlist, so unrelated meta that merely looks like ClickTrail's is never touched.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_managed_meta_key( string $key ): bool {
		return in_array( $key, self::managed_keys(), true );
	}

	/**
	 * Managed keys and values from a user's full meta (`get_user_meta( $id )` shape: key => list of values).
	 *
	 * @param array $all_meta All user meta.
	 * @return array<string,mixed> Key => first stored value.
	 */
	public static function managed_meta( array $all_meta ): array {
		$managed = array();
		foreach ( $all_meta as $key => $values ) {
			$key = (string) $key;
			if ( ! self::is_managed_meta_key( $key ) ) {
				continue;
			}
			$managed[ $key ] = is_array( $values ) ? ( $values[0] ?? '' ) : $values;
		}

		return $managed;
	}

	/**
	 * User query meta clause selecting users that carry any managed key.
	 *
	 * One anchored regular expression over the exact keys (they are `[a-z0-9_]` only).
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function managed_meta_query(): array {
		return array(
			array(
				'key'         => '^(' . implode( '|', self::managed_keys() ) . ')$',
				'compare_key' => 'REGEXP',
				'compare'     => 'EXISTS',
			),
		);
	}

	/**
	 * Export the ClickTrail registration meta of the user with this email.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array{items:array<int,array<string,mixed>>,done:bool}
	 */
	public function export_user_data( string $email, int $page = 1 ): array {
		$user = 1 === $page ? get_user_by( 'email', $email ) : false;
		if ( ! $user ) {
			return array(
				'items' => array(),
				'done'  => true,
			);
		}

		$user_id = absint( $user->ID );
		$items   = array();
		foreach ( self::managed_meta( (array) get_user_meta( $user_id ) ) as $key => $value ) {
			$items[] = array(
				'group_id'    => 'clicutcl_user_registration',
				'group_label' => __( 'ClickTrail Registration Attribution', 'click-trail-handler' ),
				'item_id'     => 'clicutcl-user-' . $user_id . '-' . sanitize_key( $key ),
				'data'        => array(
					array(
						'name'  => __( 'Metadata Key', 'click-trail-handler' ),
						'value' => sanitize_text_field( $key ),
					),
					array(
						'name'  => __( 'Metadata Value', 'click-trail-handler' ),
						'value' => $this->value_to_export( $value ),
					),
				),
			);
		}

		return array(
			'items' => $items,
			'done'  => true,
		);
	}

	/**
	 * Erase the ClickTrail registration meta of the user with this email.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase_user_data( string $email ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed  = $this->remove_user_metadata( absint( $user->ID ) );
		$retained = $removed['retained'];

		return array(
			'items_removed'  => $removed['removed'],
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Some ClickTrail registration metadata could not be deleted.', 'click-trail-handler' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * Remove expired registration meta in one bounded retention batch.
	 *
	 * @param int $days       Retention period, measured from the user's registration date.
	 * @param int $batch_size Maximum users to process.
	 * @return array{processed:int,removed:int,failed:int,remaining:bool}
	 */
	public function purge_expired_user_metadata( int $days, int $batch_size = self::BATCH_SIZE ): array {
		$days       = max( 1, $days );
		$batch_size = max( 1, $batch_size );
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$user_ids   = $this->get_user_ids(
			array(
				'number'     => $batch_size + 1,
				'date_query' => array(
					array(
						'column' => 'user_registered',
						'before' => $cutoff,
					),
				),
			)
		);

		$remaining = count( $user_ids ) > $batch_size;
		$user_ids  = array_slice( $user_ids, 0, $batch_size );
		$removed   = 0;
		$failed    = 0;
		foreach ( $user_ids as $user_id ) {
			$result   = $this->remove_user_metadata( $user_id );
			$removed += $result['removed'] ? 1 : 0;
			$failed  += $result['retained'] ? 1 : 0;
		}

		return array(
			'processed' => count( $user_ids ),
			'removed'   => $removed,
			'failed'    => $failed,
			'remaining' => $remaining || $failed > 0,
		);
	}

	/**
	 * Remove the registration meta of every user (manual purge, uninstall).
	 *
	 * @param int $batch_size Users fetched per page.
	 * @return array{processed:int,removed:int,failed:int,remaining:bool}
	 */
	public function purge_all_user_metadata( int $batch_size = self::BATCH_SIZE ): array {
		$batch_size = max( 1, $batch_size );
		$offset     = 0;
		$processed  = 0;
		$removed    = 0;
		$failed     = 0;

		while ( true ) {
			$user_ids = $this->get_user_ids(
				array(
					'number' => $batch_size,
					'offset' => $offset,
				)
			);
			if ( empty( $user_ids ) ) {
				break;
			}

			$batch_removed = 0;
			foreach ( $user_ids as $user_id ) {
				$result         = $this->remove_user_metadata( $user_id );
				$batch_removed += $result['removed'] ? 1 : 0;
				$failed        += $result['retained'] ? 1 : 0;
			}
			$processed += count( $user_ids );
			$removed   += $batch_removed;
			// Cleaned users leave the result set; only the ones that still hold meta move the offset on.
			$offset += count( $user_ids ) - $batch_removed;

			if ( count( $user_ids ) < $batch_size ) {
				break;
			}
		}

		return array(
			'processed' => $processed,
			'removed'   => $removed,
			'failed'    => $failed,
			'remaining' => $failed > 0,
		);
	}

	/**
	 * Delete every managed meta key of one user.
	 *
	 * @param int $user_id User ID.
	 * @return array{removed:bool,retained:bool} Whether something was deleted and whether something is still stored.
	 */
	private function remove_user_metadata( int $user_id ): array {
		$keys = array_keys( self::managed_meta( (array) get_user_meta( $user_id ) ) );
		foreach ( $keys as $key ) {
			delete_user_meta( $user_id, $key );
		}

		$remaining = self::managed_meta( (array) get_user_meta( $user_id ) );

		return array(
			'removed'  => ! empty( $keys ) && empty( $remaining ),
			'retained' => ! empty( $remaining ),
		);
	}

	/**
	 * IDs of users that carry ClickTrail meta.
	 *
	 * @param array<string,mixed> $args Extra user query arguments.
	 * @return int[]
	 */
	private function get_user_ids( array $args ): array {
		$ids = get_users(
			array_merge(
				array(
					'fields'      => 'ID',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'count_total' => false,
					'meta_query'  => self::managed_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded privacy purge batch.
				),
				$args
			)
		);

		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * Serialize one value without exposing non-scalar structures as PHP data.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private function value_to_export( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			$json = wp_json_encode( $value );
			return is_string( $json ) ? $json : '';
		}

		return sanitize_textarea_field( (string) $value );
	}
}
