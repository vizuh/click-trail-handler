<?php
/**
 * Records add_action()/add_filter() calls made from the Forms adapter namespace.
 *
 * Required only inside separate-process tests: the same namespaced functions are declared by other test files.
 *
 * @package ClickTrail
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions, Squiz.Commenting

namespace CLICUTCL\Integrations\Forms;

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ct_forms_registered_hooks'][] = $tag;
	return true;
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ct_forms_registered_hooks'][] = $tag;
	return true;
}
