<?php
/**
 * User and user-meta function stubs shared by the registration tests.
 *
 * State lives in `$GLOBALS['clicktrail_test_users']` (ID => object with `ID` and `user_email`) and
 * `$GLOBALS['clicktrail_test_user_meta']` (ID => key => value).
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions, Squiz.Commenting, Universal.Files.SeparateFunctionsFromOO

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return (bool) ( $GLOBALS['clicktrail_test_is_admin'] ?? false );
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return $GLOBALS['clicktrail_test_users'][ $user_id ] ?? false;
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		foreach ( $GLOBALS['clicktrail_test_users'] ?? array() as $user ) {
			if ( 'email' === $field && $user->user_email === $value ) {
				return $user;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * Core shape: no key returns key => list of values; a key returns a list, or the value when `$single`.
	 */
	function get_user_meta( $user_id, $key = '', $single = false ) {
		$all = $GLOBALS['clicktrail_test_user_meta'][ $user_id ] ?? array();
		if ( '' === $key ) {
			return array_map( static fn( $value ) => array( $value ), $all );
		}
		$value = $all[ $key ] ?? '';
		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['clicktrail_test_user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	/**
	 * Keys listed in `$GLOBALS['clicktrail_test_undeletable']` survive, like a failing meta write.
	 */
	function delete_user_meta( $user_id, $key ) {
		if ( in_array( $key, $GLOBALS['clicktrail_test_undeletable'] ?? array(), true ) ) {
			return false;
		}
		unset( $GLOBALS['clicktrail_test_user_meta'][ $user_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * IDs of users that still carry a managed key, honoring `number` and `offset`; records the arguments.
	 */
	function get_users( $args = array() ) {
		$GLOBALS['clicktrail_test_get_users_args'][] = $args;
		$ids = array();
		foreach ( $GLOBALS['clicktrail_test_user_meta'] ?? array() as $user_id => $meta ) {
			foreach ( array_keys( $meta ) as $key ) {
				if ( \CLICUTCL\Privacy\User_Registration_Privacy::is_managed_meta_key( (string) $key ) ) {
					$ids[] = $user_id;
					break;
				}
			}
		}
		return array_slice( $ids, (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? count( $ids ) ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( (string) $value );
	}
}
