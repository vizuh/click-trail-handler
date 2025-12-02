                                <p><a href="#" class="button button-primary"><?php esc_html_e( 'Fix PII Issues Now', 'click-trail-handler' ); ?></a></p>
			</div>
			<?php
		}
	}

	public function sanitize_settings( $input ) {
		$new_input = array();
		if( isset( $input['enable_attribution'] ) ) $new_input['enable_attribution'] = absint( $input['enable_attribution'] );
		if( isset( $input['cookie_days'] ) ) $new_input['cookie_days'] = absint( $input['cookie_days'] );
		if( isset( $input['enable_consent_banner'] ) ) $new_input['enable_consent_banner'] = absint( $input['enable_consent_banner'] );
		if( isset( $input['require_consent'] ) ) $new_input['require_consent'] = absint( $input['require_consent'] );
		if( isset( $input['consent_mode_region'] ) ) $new_input['consent_mode_region'] = sanitize_text_field( $input['consent_mode_region'] );
		if( isset( $input['enable_whatsapp'] ) ) $new_input['enable_whatsapp'] = absint( $input['enable_whatsapp'] );
		if( isset( $input['whatsapp_append_attribution'] ) ) $new_input['whatsapp_append_attribution'] = absint( $input['whatsapp_append_attribution'] );
		if( isset( $input['whatsapp_log_clicks'] ) ) $new_input['whatsapp_log_clicks'] = absint( $input['whatsapp_log_clicks'] );
		return $new_input;
	}

}
