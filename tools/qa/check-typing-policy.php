<?php
/**
 * Enforce ClickTrail typing policy.
 *
 * Policy:
 * - Do not mix strict and weak typing modes in runtime plugin files.
 * - Until a full typed migration is complete, `declare(strict_types=1);`
 *   is forbidden in this repository.
 */

$root = dirname( __DIR__, 2 );

$exclude_dirs = array(
	'.git',
	'vendor',
	'node_modules',
	'languages',
	'docs',
);

$violations = array();
$pattern    = '/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/i';

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	$path = (string) $file->getPathname();
	if ( 'php' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
		continue;
	}

	$normalized = str_replace( '\\', '/', $path );
	$skip       = false;
	foreach ( $exclude_dirs as $dir ) {
		if ( false !== strpos( $normalized, '/' . $dir . '/' ) ) {
			$skip = true;
			break;
		}
	}
	if ( $skip ) {
		continue;
	}

	$content = file_get_contents( $path );
	if ( false === $content ) {
		continue;
	}

	if ( preg_match( $pattern, $content ) ) {
		$violations[] = str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
	}
}

if ( ! empty( $violations ) ) {
	echo "Typing policy violation: strict_types declarations are not allowed right now.\n";
	foreach ( $violations as $violation ) {
		echo ' - ' . $violation . "\n";
	}
	exit( 1 );
}

echo "Typing policy check passed.\n";
exit( 0 );
