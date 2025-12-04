<?php
/**
 * ClickTrail Autoloader
 *
 * @package ClickTrail
 */

namespace CLICUTCL;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader class.
 */
class Autoloader {

	/**
	 * Run autoloader.
	 *
	 * Register the autoloader.
	 */
	public static function run() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload.
	 *
	 * For a given class, check if it exists and load it.
	 *
	 * @param string $class Class name.
	 */
	private static function autoload( $class ) {
		// Only load CLICUTCL classes.
		if ( 0 !== strpos( $class, 'CLICUTCL\\' ) ) {
			return;
		}

		// Remove the root namespace.
		$relative_class = str_replace( 'CLICUTCL\\', '', $class );

		// Convert namespace to path.
		// 1. Lowercase.
		// 2. Replace backslashes with directory separators.
		// 3. Replace underscores with hyphens (WordPress convention).
		$file_parts = explode( '\\', $relative_class );
		
		// The last part is the file name.
		$file_name = array_pop( $file_parts );
		
		// The rest is the directory path.
		$directory_path = implode( DIRECTORY_SEPARATOR, $file_parts );
		
		// Format file name: class-{class-name}.php, lowercase, hyphens.
		$file_name = 'class-' . str_replace( '_', '-', strtolower( $file_name ) ) . '.php';
		
		// Format directory path: lowercase, hyphens.
		$directory_path = str_replace( '_', '-', strtolower( $directory_path ) );

		// Build full path.
		$path = CLICUTCL_DIR . 'includes' . DIRECTORY_SEPARATOR . $directory_path . DIRECTORY_SEPARATOR . $file_name;
		
		// Handle root includes (no subdirectory)
		if ( empty( $directory_path ) ) {
			$path = CLICUTCL_DIR . 'includes' . DIRECTORY_SEPARATOR . $file_name;
		}

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
