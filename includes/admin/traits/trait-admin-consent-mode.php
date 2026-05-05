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

	public function render_consent_checkbox( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$settings = new Consent_Mode_Settings();
		$value    = $settings->get();
		$enabled  = isset( $value['enabled'] ) ? $value['enabled'] : 0;
		?>
		<div class="clicktrail-toggle-wrapper">
			<label class="clicktrail-toggle">
				<input type="hidden" name="clicutcl_consent_mode[enabled]" value="0" />
				<input type="checkbox" name="clicutcl_consent_mode[enabled]" value="1" <?php checked( 1, $enabled ); ?> />
				<span class="clicktrail-toggle-slider"></span>
			</label>
		</div>
		<?php
	}

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
		<?php
	}

	public function render_regions_field( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$settings = new Consent_Mode_Settings();
		$value    = $settings->get();
		$regions  = isset( $value['regions'] ) ? $value['regions'] : '';
		if ( is_array( $regions ) ) {
			$regions = implode( ', ', $regions );
		}
		?>
		<input type="text" name="clicutcl_consent_mode[regions]" value="<?php echo esc_attr( $regions ); ?>" class="regular-text clicktrail-field-input" placeholder="EEA, UK, US-CA" />
		<p class="description"><?php esc_html_e( 'Use region codes such as EEA, UK, US, or US-CA.', 'click-trail-handler' ); ?></p>
		<?php
	}

	public function render_cmp_source_field( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$settings   = new Consent_Mode_Settings();
		$value      = $settings->get();
		$cmp_source = isset( $value['cmp_source'] ) ? sanitize_key( (string) $value['cmp_source'] ) : 'auto';
		$options    = array(
			'auto'      => __( 'Auto-detect', 'click-trail-handler' ),
			'plugin'    => __( 'ClickTrail built-in banner', 'click-trail-handler' ),
			'cookiebot' => __( 'Cookiebot / Usercentrics', 'click-trail-handler' ),
			'onetrust'  => __( 'OneTrust', 'click-trail-handler' ),
			'complianz' => __( 'Complianz', 'click-trail-handler' ),
			'gtm'       => __( 'Google Consent Mode via GTM', 'click-trail-handler' ),
			'custom'    => __( 'Custom integration', 'click-trail-handler' ),
		);
		?>
		<select id="clicutcl_cmp_source" name="clicutcl_consent_mode[cmp_source]" class="clicktrail-field-select">
			<?php foreach ( $options as $option_value => $label ) : ?>
				<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $cmp_source, $option_value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the consent platform ClickTrail should listen to.', 'click-trail-handler' ); ?>
		</p>
		<?php
	}

	public function render_cmp_timeout_field( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$settings = new Consent_Mode_Settings();
		$value    = $settings->get();
		$timeout  = isset( $value['cmp_timeout_ms'] ) ? absint( $value['cmp_timeout_ms'] ) : 3000;
		$timeout  = min( 10000, max( 500, $timeout ) );
		?>
		<input
			type="number"
			id="clicutcl_cmp_timeout"
			name="clicutcl_consent_mode[cmp_timeout_ms]"
			value="<?php echo esc_attr( (string) $timeout ); ?>"
			min="500"
			max="10000"
			step="100"
			class="small-text clicktrail-field-input clicktrail-field-input--narrow"
		/>
		<p class="description">
			<?php esc_html_e( 'How long to wait for a consent response before treating it as denied.', 'click-trail-handler' ); ?>
		</p>
		<?php
	}
}
