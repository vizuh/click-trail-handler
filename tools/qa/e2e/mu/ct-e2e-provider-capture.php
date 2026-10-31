<?php
/**
 * E2E only: record what the form providers hold at submit time (CF7 posted data, WPForms processed fields).
 *
 * Read-only observers at the latest priority; they change nothing. WPForms Lite has no entries table and
 * CF7 has no native storage, so this is the "provider entry" for those two. Never deploy.
 *
 * @package ClickTrail
 */

/**
 * Append one JSON line to the provider capture file.
 *
 * @param array $row Row to record.
 * @return void
 */
function ct_e2e_provider_log( array $row ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Throwaway E2E capture file.
	file_put_contents( WP_CONTENT_DIR . '/ct-provider.jsonl', wp_json_encode( $row ) . "\n", FILE_APPEND );
}

add_action(
	'wpcf7_mail_sent',
	static function ( $contact_form ) {
		$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		if ( ! $submission ) {
			return;
		}
		ct_e2e_provider_log(
			array(
				'provider' => 'cf7',
				'form_id'  => $contact_form->id(),
				'fields'   => (array) $submission->get_posted_data(),
			)
		);
	},
	99
);

add_action(
	'wpforms_process_complete',
	static function ( $fields, $entry, $form_data, $entry_id ) {
		$flat = array();
		foreach ( (array) $fields as $field ) {
			$flat[ (string) ( $field['name'] ?? $field['id'] ?? '' ) ] = $field['value'] ?? '';
		}
		ct_e2e_provider_log(
			array(
				'provider'        => 'wpforms',
				'form_id'         => (int) ( $form_data['id'] ?? 0 ),
				'entry_id'        => (int) $entry_id,
				'entry_meta_api'  => function_exists( 'wpforms' ) && isset( wpforms()->entry_meta ),
				'fields'          => $flat,
			)
		);
	},
	99,
	4
);
