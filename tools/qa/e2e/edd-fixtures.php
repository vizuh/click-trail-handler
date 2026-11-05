<?php
/**
 * E2E fixtures for Easy Digital Downloads: Store (manual) gateway only, one priced download, pretty permalinks.
 * Run with `wp eval-file`; prints `@@JSON@@{...}` with the IDs and URLs the scenarios need.
 */

// WP-CLI activation never fires admin_init, which is where EDD installs its order, session and meta tables.
edd_install_component_database_tables();

edd_update_option( 'gateways', array( 'manual' => 1 ) );
edd_update_option( 'default_gateway', 'manual' );
edd_update_option( 'test_mode', 1 );
edd_update_option( 'currency', 'EUR' );
edd_update_option( 'enable_sequential', 0 );

$download_id = wp_insert_post(
	array(
		'post_type'   => 'download',
		'post_status' => 'publish',
		'post_title'  => 'E2E Download',
	)
);
update_post_meta( $download_id, 'edd_price', '12.00' );
update_post_meta( $download_id, '_variable_pricing', 0 );

flush_rewrite_rules();

echo '@@JSON@@' . wp_json_encode(
	array(
		'download_id' => (int) $download_id,
		'checkout'    => edd_get_checkout_uri(),
		'success'     => edd_get_success_page_uri(),
		'edd_version' => defined( 'EDD_VERSION' ) ? EDD_VERSION : '',
	)
);
