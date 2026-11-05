<?php
/**
 * Formidable Forms doubles for the Formidable adapter tests.
 *
 * Required only inside separate-process tests, and only by the tests that need Formidable "active".
 * Mirrors the Formidable 7.0 signatures the adapter calls: `FrmField::get_all_for_form()`,
 * `FrmEntry::getOne()`, `FrmEntryMeta::add_entry_meta()` and `FrmEntryMeta::get_entry_meta_info()`.
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions, Squiz.Commenting, Generic.Files.OneObjectStructurePerFile

if ( ! class_exists( 'FrmAppHelper' ) ) {
	class FrmAppHelper {}
}

if ( ! class_exists( 'FrmField' ) ) {
	class FrmField {
		/**
		 * Fields per form ID.
		 *
		 * @var array<int,array<int,object>>
		 */
		public static $forms = array();

		public static function get_all_for_form( $form_id, $limit = '', $inc_embed = 'exclude', $inc_repeat = 'include' ) {
			return self::$forms[ (int) $form_id ] ?? array();
		}
	}
}

if ( ! class_exists( 'FrmEntry' ) ) {
	class FrmEntry {
		/**
		 * Entries by ID.
		 *
		 * @var array<int,object>
		 */
		public static $entries = array();

		public static function getOne( $id, $meta = false ) {
			return self::$entries[ (int) $id ] ?? null;
		}
	}
}

if ( ! class_exists( 'FrmEntryMeta' ) ) {
	class FrmEntryMeta {
		/**
		 * `frm_item_metas` rows.
		 *
		 * @var object[]
		 */
		public static $rows = array();

		public static function add_entry_meta( $entry_id, $field_id, $meta_key, $meta_value, $field = null ) {
			self::$rows[] = (object) array(
				'item_id'    => $entry_id,
				'field_id'   => $field_id,
				'meta_value' => is_array( $meta_value ) ? serialize( $meta_value ) : $meta_value,
			);
			return count( self::$rows );
		}

		public static function get_entry_meta_info( $entry_id ) {
			return array_values(
				array_filter(
					self::$rows,
					static function ( $row ) use ( $entry_id ) {
						return (int) $row->item_id === (int) $entry_id;
					}
				)
			);
		}
	}
}

/**
 * A Formidable field object as `FrmField` returns it.
 *
 * @param int    $id        Field ID.
 * @param string $type      Field type.
 * @param string $field_key Field key.
 * @param string $name      Field label.
 * @return object
 */
function ct_frm_field( $id, $type, $field_key, $name = '' ) {
	return (object) array(
		'id'        => $id,
		'type'      => $type,
		'field_key' => $field_key,
		'name'      => $name,
	);
}
