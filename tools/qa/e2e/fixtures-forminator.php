<?php
/**
 * E2E fixtures: three Forminator forms (AJAX mode) plus one page each. Run with `wp eval-file`, after fixtures.php.
 *
 * The forms differ only in how the Hidden fields are marked as ClickTrail's, and in the Default value Forminator
 * would otherwise apply, so every marker style the adapter accepts is exercised in a real submission:
 *
 * - class:  custom CSS class `ct_<key>`, Default value "Custom value" `forminator-static` (Forminator would
 *           otherwise rewrite the stored value to that text after every filter).
 * - label:  label exactly `ct_<key>`, Default value "User IP Address" (Forminator's own default).
 * - custom: Default value "Custom value" set to exactly `ct_<key>`.
 *
 * Hidden fields are hidden-1..hidden-6 in the order of $ct_keys. Every form mails `GCLID: {hidden-1}`.
 */

$ct_keys  = array( 'gclid', 'fbclid', 'ft_source', 'lt_source', 'lt_medium', 'attribution_selected_click_id' );
$variants = array(
	'class'  => 'form-forminator',
	'label'  => 'form-forminator-label',
	'custom' => 'form-forminator-custom',
);
$ids      = array();

foreach ( $variants as $variant => $slug ) {
	$wrappers = array(
		array(
			'wrapper_id' => 'wrapper-1-1',
			'fields'     => array( array( 'element_id' => 'text-1', 'type' => 'text', 'cols' => '12', 'field_label' => 'Name', 'required' => 'false', 'input_type' => 'line' ) ),
		),
		array(
			'wrapper_id' => 'wrapper-2-1',
			'fields'     => array( array( 'element_id' => 'email-1', 'type' => 'email', 'cols' => '12', 'field_label' => 'Email', 'required' => 'true', 'validation' => 'true' ) ),
		),
	);
	foreach ( $ct_keys as $i => $key ) {
		$field = array(
			'element_id'    => 'hidden-' . ( $i + 1 ),
			'type'          => 'hidden',
			'cols'          => '12',
			'field_label'   => 'Hidden ' . $key,
			'default_value' => 'user_ip',
		);
		if ( 'class' === $variant ) {
			$field['custom-class']  = 'forminator-tracking ct_' . $key;
			$field['default_value'] = 'custom_value';
			$field['custom_value']  = 'forminator-static';
		} elseif ( 'label' === $variant ) {
			$field['field_label'] = 'ct_' . $key;
		} else {
			$field['default_value'] = 'custom_value';
			$field['custom_value']  = 'ct_' . $key;
		}
		$wrappers[] = array( 'wrapper_id' => 'wrapper-' . ( 3 + $i ) . '-1', 'fields' => array( $field ) );
	}

	$form_id = Forminator_API::add_form(
		'E2E Forminator ' . $variant,
		$wrappers,
		array( 'formName' => 'E2E Forminator ' . $variant, 'enable-ajax' => 'true', 'store_submissions' => '1' )
	);
	if ( is_wp_error( $form_id ) ) {
		fwrite( STDERR, 'Forminator form ' . $variant . ': ' . $form_id->get_error_message() . "\n" );
		exit( 1 );
	}

	$model                       = Forminator_Base_Form_Model::get_model( $form_id );
	$notifications               = $model->notifications;
	$notifications[0]['email-editor'] = 'GCLID: {hidden-1}<br/>SOURCE: {hidden-4}<br/>{all_fields}';
	$model->notifications        = $notifications;
	$model->save();

	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $slug,
			'post_name'    => $slug,
			'post_content' => '[forminator_form id="' . $form_id . '"]',
		)
	);
	$ids[ $variant ] = (int) $form_id;
}

echo wp_json_encode( array( 'forminator' => $ids ) ) . "\n";
