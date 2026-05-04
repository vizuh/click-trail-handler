<?php
/**
 * Admin consent-mode rendering trait.
 *
 * @package ClickTrail
 */

namespace CLICUTCL\Admin;

use CLICUTCL\Modules\Consent_Mode\Consent_Mode_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Admin_Consent_Mode_Trait {

	public function render_consent_mode_field( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$settings = new Consent_Mode_Settings();
		$value    = $settings->get();
		$mode     = isset( $value['mode'] ) ? sanitize_key( (string) $value['mode'] ) : 'strict';
		$options  = array(
			'strict'  => __( 'Wait for consent', 'click-trail-handler' ),
			'relaxed' => __( 'Allow until denied', 'click-trail-handler' ),
			'geo'     => __( 'Require consent by region', 'click-trail-handler' ),
		);
		?>
		<select id="clicutcl_consent_mode_behavior" name="clicutcl_consent_mode[mode]" class="clicktrail-field-select">
			<?php foreach ( $options as $option_value => $label ) : ?>
				<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $mode, $option_value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Choose when attribution is allowed to start for a visitor.', 'click-trail-handler' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'UTM parameters and click IDs are always buffered to session storage the moment a visitor lands — before any consent banner fires. When the visitor accepts, that data is promoted to the attribution cookie. First-touch attribution is preserved even if consent is accepted on a later page.', 'click-trail-handler' ); ?>
		</p>
		<?php
	}
}
