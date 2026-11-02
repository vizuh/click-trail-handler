<?php
/**
 * E2E fixtures: Fluent Forms, WPForms Lite and Formidable forms plus one page per provider. Run with `wp eval-file`.
 *
 * Every form carries the same ClickTrail hidden fields so the three providers are compared like for like.
 */

$ct_keys = array( 'gclid', 'fbclid', 'ft_source', 'lt_source', 'lt_medium', 'attribution_selected_click_id' );

// --- Fluent Forms: name, email, one native hidden field per ClickTrail key.
$fields = array(
	array(
		'index'          => 0,
		'element'        => 'input_text',
		'attributes'     => array( 'type' => 'text', 'name' => 'your_name', 'value' => '', 'id' => '', 'class' => '', 'placeholder' => 'Name' ),
		'settings'       => array( 'container_class' => '', 'label' => 'Name', 'label_placement' => '', 'help_message' => '', 'admin_field_label' => '', 'validation_rules' => array( 'required' => array( 'value' => false, 'message' => 'This field is required' ) ), 'conditional_logics' => array() ),
		'editor_options' => array( 'title' => 'Simple Text', 'icon_class' => 'ff-edit-text', 'template' => 'inputText' ),
	),
	array(
		'index'          => 1,
		'element'        => 'input_email',
		'attributes'     => array( 'type' => 'email', 'name' => 'email', 'value' => '', 'id' => '', 'class' => '', 'placeholder' => 'Email' ),
		'settings'       => array( 'container_class' => '', 'label' => 'Email', 'label_placement' => '', 'help_message' => '', 'admin_field_label' => '', 'validation_rules' => array( 'required' => array( 'value' => true, 'message' => 'This field is required' ), 'email' => array( 'value' => true, 'message' => 'This field must contain a valid email' ) ), 'conditional_logics' => array() ),
		'editor_options' => array( 'title' => 'Email Address', 'icon_class' => 'ff-edit-email', 'template' => 'inputText' ),
	),
);
foreach ( $ct_keys as $i => $key ) {
	$fields[] = array(
		'index'          => 2 + $i,
		'element'        => 'input_hidden',
		'attributes'     => array( 'type' => 'hidden', 'name' => 'ct_' . $key, 'value' => '' ),
		'settings'       => array( 'admin_field_label' => 'ct_' . $key, 'conditional_logics' => array() ),
		'editor_options' => array( 'title' => 'Hidden Field', 'icon_class' => 'ff-edit-hidden', 'template' => 'inputHidden' ),
	);
}
$form_fields = array(
	'fields'       => $fields,
	'submitButton' => array(
		'uniqElKey'      => 'el_1',
		'element'        => 'button',
		'attributes'     => array( 'type' => 'submit', 'class' => '' ),
		'settings'       => array( 'align' => 'left', 'button_style' => 'default', 'container_class' => '', 'help_message' => '', 'background_color' => '#409EFF', 'button_size' => 'md', 'color' => '#ffffff', 'button_ui' => array( 'type' => 'default', 'text' => 'Submit Form', 'img_url' => '' ), 'normal_styles' => array(), 'hover_styles' => array(), 'current_state' => 'normal_styles' ),
		'editor_options' => array( 'title' => 'Submit Button' ),
	),
);
$now = current_time( 'mysql' );
wpFluent()->table( 'fluentform_forms' )->insert(
	array(
		'title'       => 'E2E Fluent',
		'status'      => 'published',
		'type'        => 'form',
		'form_fields' => wp_json_encode( $form_fields ),
		'has_payment' => 0,
		'created_at'  => $now,
		'updated_at'  => $now,
	)
);
$fluent_id = (int) wpFluent()->table( 'fluentform_forms' )->orderBy( 'id', 'DESC' )->first()->id;
wpFluent()->table( 'fluentform_form_meta' )->insert(
	array(
		'form_id'  => $fluent_id,
		'meta_key' => 'formSettings',
		'value'    => wp_json_encode(
			array(
				'confirmation' => array( 'redirectTo' => 'samePage', 'messageToShow' => 'E2E thanks', 'customPage' => null, 'samePageFormBehavior' => 'hide_form', 'customUrl' => null ),
				'restrictions' => array( 'limitNumberOfEntries' => array( 'enabled' => false ), 'scheduleForm' => array( 'enabled' => false ), 'requireLogin' => array( 'enabled' => false ), 'denyEmptySubmission' => array( 'enabled' => false ) ),
				'layout'       => array( 'labelPlacement' => 'top', 'helpMessagePlacement' => 'with_label', 'errorMessagePlacement' => 'inline', 'cssClassName' => '' ),
			)
		),
	)
);

// --- WPForms Lite: name, email, one field per ClickTrail key marked with the `ct_<key>` CSS class.
// Two forms: the documented `hidden` field type (a Pro field: Lite 2.0.3.1 renders nothing for it) and a
// single-line text field that carries the same class and the built-in `wpforms-field-hidden` utility class.
$wpforms_ids = array();
foreach ( array( 'hidden' => 'hidden', 'text' => 'text' ) as $variant => $field_type ) {
	$form_id = wp_insert_post(
		array(
			'post_type'   => 'wpforms',
			'post_status' => 'publish',
			'post_title'  => 'E2E WPForms ' . $variant,
		)
	);
	$wpf_fields = array(
		'1' => array( 'id' => '1', 'type' => 'name', 'label' => 'Name', 'format' => 'simple', 'required' => '0', 'size' => 'medium' ),
		'2' => array( 'id' => '2', 'type' => 'email', 'label' => 'Email', 'required' => '1', 'size' => 'medium' ),
	);
	foreach ( $ct_keys as $i => $key ) {
		$id                = (string) ( 3 + $i );
		$wpf_fields[ $id ] = array(
			'id'    => $id,
			'type'  => $field_type,
			'label' => 'Hidden ' . $key,
			'css'   => 'hidden' === $variant ? 'ct_' . $key : 'ct_' . $key . ' wpforms-field-hidden',
			'size'  => 'medium',
		);
	}
	$wpf_data = array(
		'id'       => $form_id,
		'field_id' => 3 + count( $ct_keys ),
		'fields'   => $wpf_fields,
		'settings' => array(
			'form_title'             => 'E2E WPForms ' . $variant,
			'submit_text'            => 'Submit',
			'submit_text_processing' => 'Sending...',
			'ajax_submit'            => '1',
			'notification_enable'    => '1',
			'notifications'          => array( '1' => array( 'email' => 'admin@example.test', 'subject' => 'E2E', 'sender_name' => 'E2E', 'sender_address' => 'a@example.test', 'message' => '{all_fields}' ) ),
			'confirmations'          => array( '1' => array( 'type' => 'message', 'message' => 'E2E thanks', 'message_scroll' => '0' ) ),
		),
	);
	wp_update_post(
		array(
			'ID'           => $form_id,
			'post_content' => wp_slash( wp_json_encode( $wpf_data ) ),
		)
	);
	$wpforms_ids[ $variant ] = (int) $form_id;
}

// --- Formidable Forms (free): name, email, one native hidden field per ClickTrail key. The field key is the
// marker (`ct_<key>`); Formidable keeps field keys unique across forms, so the keys are free only because this is
// the one Formidable form.
// Formidable creates its tables on admin_init, which wp-cli never fires: run its installer.
FrmAppController::install();
$formidable_id = (int) FrmForm::create(
	array(
		'form_key'    => 'e2e-formidable',
		'name'        => 'E2E Formidable',
		'description' => '',
		'status'      => 'published',
		'options'     => array(
			'success_msg'  => 'E2E thanks',
			'submit_value' => 'Submit',
			'ajax_submit'  => 1,
		),
	)
);
$frm_fields = array(
	array( 'type' => 'text', 'name' => 'Name', 'field_key' => 'e2e_name' ),
	array( 'type' => 'email', 'name' => 'Email', 'field_key' => 'e2e_email', 'required' => 1 ),
);
// `ft_content` / `lt_content` are `utm_content` (the HandL "utm_content not passing" pitfall, #169).
foreach ( array_merge( $ct_keys, array( 'ft_content', 'lt_content' ) ) as $key ) {
	$frm_fields[] = array( 'type' => 'hidden', 'name' => 'Hidden ' . $key, 'field_key' => 'ct_' . $key );
}
foreach ( $frm_fields as $frm_field ) {
	$values              = FrmFieldsHelper::setup_new_vars( $frm_field['type'], $formidable_id );
	$values['name']      = $frm_field['name'];
	$values['field_key'] = $frm_field['field_key'];
	$values['required']  = $frm_field['required'] ?? 0;
	FrmField::create( $values );
}

// An email notification that prints three hidden fields: the issue's "entry and email contain all three".
$email_action   = FrmFormActionsController::get_form_actions( 'email' );
$email_settings = $email_action->prepare_new( $formidable_id );
$email_settings->post_content = array_merge(
	$email_settings->post_content,
	array(
		'email_to'      => 'admin@example.test',
		'email_subject' => 'E2E Formidable',
		'email_message' => "GCLID: [ct_gclid]\nSOURCE: [ct_lt_source]\nCONTENT: [ct_lt_content]",
	)
);
$email_action->save_settings( $email_settings );

// --- One page per provider, same URL shape.
$cf7_id = (int) get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => 1, 'fields' => 'ids' ) )[0];

// CF7 renders a ct_* hidden field only for keys the form references (#168): the main form references
// every key the scenarios assert, one form references none.
$cf7_main = WPCF7_ContactForm::get_instance( $cf7_id );
$props    = $cf7_main->get_properties();
$props['form'] .= "\n" . implode( "\n", array_map( static fn( $k ) => '[hidden ct_' . $k . ']', $ct_keys ) );
$props['mail']['body'] .= "\nGCLID: [ct_gclid]";
$cf7_main->set_properties( $props );
$cf7_main->save();
$cf7_plain = WPCF7_ContactForm::get_template();
$cf7_plain->set_title( 'E2E CF7 no ct references' );
$cf7_plain->save();
$cf7_plain_id = (int) $cf7_plain->id();

$pages  = array(
	'form-cf7-plain' => '[contact-form-7 id="' . $cf7_plain_id . '"]',
	'form-cf7'     => '[contact-form-7 id="' . $cf7_id . '"]',
	'form-fluent'  => '[fluentform id="' . $fluent_id . '"]',
	'form-wpforms'      => '[wpforms id="' . $wpforms_ids['hidden'] . '"]',
	'form-wpforms-text' => '[wpforms id="' . $wpforms_ids['text'] . '"]',
	'form-formidable'   => '[formidable id="' . $formidable_id . '"]',
);
foreach ( $pages as $slug => $content ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_content' => $content ) );
}
echo wp_json_encode( array( 'cf7' => $cf7_id, 'cf7_plain' => $cf7_plain_id, 'fluent' => $fluent_id, 'wpforms_hidden' => $wpforms_ids['hidden'], 'wpforms_text' => $wpforms_ids['text'], 'formidable' => $formidable_id ) ) . "\n";
