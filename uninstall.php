<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package ClickTrail
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options.
delete_option( 'clicutcl_attribution_settings' );
delete_option( 'clicutcl_consent_mode' );
delete_option( 'clicutcl_gtm' );
delete_option( 'clicutcl_pii_risk_detected' );

// Note: We do NOT delete the 'clicutcl_wa_click' post type data by default,
// as users may want to keep their historical click data even if they uninstall the plugin.
// If complete data removal is required, a specific "Delete Data on Uninstall" setting should be added.
