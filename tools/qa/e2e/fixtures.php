<?php
/**
 * E2E fixtures: Fluent Forms and WPForms Lite forms plus one page per provider. Run with `wp eval-file`.
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

// --- One page per provider, same URL shape.
$cf7_id = (int) get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => 1, 'fields' => 'ids' ) )[0];
$pages  = array(
	'form-cf7'     => '[contact-form-7 id="' . $cf7_id . '"]',
	'form-fluent'  => '[fluentform id="' . $fluent_id . '"]',
	'form-wpforms'      => '[wpforms id="' . $wpforms_ids['hidden'] . '"]',
	'form-wpforms-text' => '[wpforms id="' . $wpforms_ids['text'] . '"]',
);
foreach ( $pages as $slug => $content ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug, 'post_content' => $content ) );
}
echo wp_json_encode( array( 'cf7' => $cf7_id, 'fluent' => $fluent_id, 'wpforms_hidden' => $wpforms_ids['hidden'], 'wpforms_text' => $wpforms_ids['text'] ) ) . "\n";
