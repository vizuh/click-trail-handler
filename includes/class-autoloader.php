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

		// Explode parts
		$parts = explode( '\\', $relative_class );
		
		// The last part is the file name.
		$class_name = array_pop( $parts );
		
		// Format file name: class-{class-name}.php, lowercase, hyphens.
		$file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';
		
		// Build directory candidates (Original vs Lowercase-Kebab)
		$path_segments = array();
		foreach ( $parts as $part ) {
			$options = array();
			$options[] = $part; // 1. Original Case (e.g. "Modules")
			
			$lower_kebab = strtolower( str_replace( '_', '-', $part ) );
			if ( $lower_kebab !== $part ) {
				$options[] = $lower_kebab; // 2. Lowercase Kebab (e.g. "consent-mode")
			}
			$path_segments[] = array_unique( $options );
		}

		// Calculate cartesian product of directories
		// Start with an empty path
		$paths = array( '' );
		foreach ( $path_segments as $segment_options ) {
			$new_paths = array();
			foreach ( $paths as $base_path ) {
				foreach ( $segment_options as $option ) {
					$new_paths[] = $base_path . $option . DIRECTORY_SEPARATOR;
				}
			}
			$paths = $new_paths;
		}

		// Check all candidate paths
		foreach ( $paths as $dir ) {
			// Handle root includes (no subdirectory)
			if ( empty( $dir ) ) {
				$path = CLICUTCL_DIR . 'includes' . DIRECTORY_SEPARATOR . $file_name;
			} else {
				$path = CLICUTCL_DIR . 'includes' . DIRECTORY_SEPARATOR . $dir . $file_name;
			}
			
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
