<?php
/**
 * E2E fixtures: Fluent Forms, WPForms Lite and SureForms forms plus one page per provider. Run with `wp eval-file`.
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

// --- MetForm: one form per builder, same hidden `ct_<key>` inputs and a stored entry plus an admin email that lists every field.
// Block builder (MetForm 4.1.8+, no Elementor needed): `hiddenfield` blocks, Static value, empty.
// Elementor builder: the Submit Button widget's Hidden Input list, empty Input Value.
$mf_settings = array(
	'form_title'                 => 'E2E MetForm',
	'store_entries'              => 1,
	'success_message'            => 'E2E thanks',
	'hide_form_after_submission' => '',
	'enable_admin_notification'  => 1,
	'admin_email_to'             => 'admin@example.test',
	'admin_email_subject'        => 'E2E MetForm',
	'admin_email_from'           => 'a@example.test',
	'admin_email_reply_to'       => 'a@example.test',
	'admin_email_body'           => 'E2E MetForm submission',
);

$mf_block_id = wp_insert_post( array( 'post_type' => 'metform-form', 'post_status' => 'publish', 'post_title' => 'E2E MetForm block builder' ) );
$mf_elements = array(
	array( 'id' => 'e2e-name', 'type' => 'input', 'props' => array( 'name' => 'mf-name', 'label' => 'Name' ) ),
	array( 'id' => 'e2e-email', 'type' => 'email', 'props' => array( 'name' => 'mf-email', 'label' => 'Email', 'required' => true ) ),
);
foreach ( $ct_keys as $key ) {
	$mf_elements[] = array( 'id' => 'e2e-ct-' . $key, 'type' => 'hiddenfield', 'props' => array( 'name' => 'ct_' . $key, 'valueSource' => 'static', 'value' => '' ) );
}
$mf_elements[] = array( 'id' => 'e2e-submit', 'type' => 'button', 'props' => array( 'text' => 'Submit', 'buttonType' => 'submit' ) );
update_post_meta( $mf_block_id, '_metform_editor_mode', 'metform' );
update_post_meta( $mf_block_id, '_metform_builder_document', array( 'version' => '1.0.0', 'elements' => $mf_elements ) );
update_post_meta( $mf_block_id, 'metform_form__form_setting', $mf_settings );

$mf_el_id    = wp_insert_post( array( 'post_type' => 'metform-form', 'post_status' => 'publish', 'post_title' => 'E2E MetForm Elementor builder' ) );
$mf_hidden   = array();
foreach ( $ct_keys as $i => $key ) {
	$mf_hidden[] = array( '_id' => 'e2ehid' . $i, 'mf_hidden_input_name' => 'ct_' . $key, 'mf_hidden_input_value' => '', 'mf_hidden_input_class' => '' );
}
$mf_el_data = array(
	array(
		'id'       => 'e2esec1',
		'elType'   => 'section',
		'settings' => array(),
		'elements' => array(
			array(
				'id'       => 'e2ecol1',
				'elType'   => 'column',
				'settings' => array( '_column_size' => 100 ),
				'elements' => array(
					array( 'id' => 'e2ew1', 'elType' => 'widget', 'widgetType' => 'mf-text', 'settings' => array( 'mf_input_name' => 'mf-name', 'mf_input_label' => 'Name' ), 'elements' => array() ),
					array( 'id' => 'e2ew2', 'elType' => 'widget', 'widgetType' => 'mf-email', 'settings' => array( 'mf_input_name' => 'mf-email', 'mf_input_label' => 'Email', 'mf_input_required' => 'yes' ), 'elements' => array() ),
					array( 'id' => 'e2ew3', 'elType' => 'widget', 'widgetType' => 'mf-button', 'settings' => array( 'mf_btn_text' => 'Submit', 'mf_hidden_input' => $mf_hidden ), 'elements' => array() ),
				),
			),
		),
	),
);
update_post_meta( $mf_el_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $mf_el_id, '_elementor_data', wp_slash( wp_json_encode( $mf_el_data ) ) );
update_post_meta( $mf_el_id, 'metform_form__form_setting', $mf_settings );

// --- SureForms (free): name, email, one Text field per ClickTrail key whose label is `ct_<key>` (free SureForms has no
// Hidden field; the same label rule covers the Pro Hidden field). The form is a block post; formId is set after the insert.
$sureforms_id = 0;
if ( defined( 'SRFM_FORMS_POST_TYPE' ) ) {
	wp_set_current_user( 1 );
	$sf_id = wp_insert_post(
		array(
			'post_type'   => SRFM_FORMS_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => 'E2E SureForms',
		)
	);
	$sf_block = static function ( $type, $block_id, $label, $slug, $required = false ) use ( $sf_id ) {
		return '<!-- wp:srfm/' . $type . ' ' . wp_json_encode(
			array(
				'required' => $required,
				'block_id' => $block_id,
				'formId'   => $sf_id,
				'slug'     => $slug,
				'label'    => $label,
			)
		) . ' /-->';
	};
	$sf_content = array(
		$sf_block( 'input', 'a1b2c3d1', 'Name', 'name' ),
		$sf_block( 'email', 'a1b2c3d2', 'Email', 'email', true ),
	);
	foreach ( $ct_keys as $i => $key ) {
		$sf_content[] = $sf_block( 'input', sprintf( 'c7e2a%03d', $i ), 'ct_' . $key, 'ct-' . str_replace( '_', '-', $key ) );
	}
	wp_update_post(
		array(
			'ID'           => $sf_id,
			'post_content' => wp_slash( implode( "\n\n", $sf_content ) ),
		)
	);
	$sureforms_id = (int) $sf_id;
}

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
	'form-metform'      => '[metform form_id="' . $mf_block_id . '"]',
	'form-metform-el'   => '[metform form_id="' . $mf_el_id . '"]',
);
if ( $sureforms_id ) {
	$pages['form-sureforms'] = '[sureforms id="' . $sureforms_id . '"]';
}
foreach ( $pages as $slug => $content ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_content' => $content ) );
}
echo wp_json_encode( array( 'cf7' => $cf7_id, 'cf7_plain' => $cf7_plain_id, 'fluent' => $fluent_id, 'wpforms_hidden' => $wpforms_ids['hidden'], 'wpforms_text' => $wpforms_ids['text'], 'sureforms' => $sureforms_id, 'metform_block' => $mf_block_id, 'metform_elementor' => $mf_el_id ) ) . "\n";
