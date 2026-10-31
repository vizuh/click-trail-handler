<?php
/**
 * E2E only: opt in to server-rendered attribution values (#152) while the option `ct_e2e_render_values` is truthy.
 *
 * Lets the cache scenario produce the HTML a pre-#177 install would have cached, to prove the submit layer
 * still blanks it. Never deploy.
 *
 * @package ClickTrail
 */

add_filter(
	'clicutcl_render_server_attribution_values',
	static function ( $enabled ) {
		return get_option( 'ct_e2e_render_values' ) ? true : $enabled;
	}
);
