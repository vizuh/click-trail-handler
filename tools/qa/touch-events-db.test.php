<?php
/**
 * Disposable WordPress/MariaDB check for migration and atomic event dedup.
 * Run: CLICKTRAIL_DISPOSABLE_DB_TEST=1 wp eval-file tools/qa/touch-events-db.test.php
 * Requires a fresh local WordPress database with no ClickTrail tables.
 *
 * @package ClickTrail
 */

use CLICUTCL\Database\Installer;
use CLICUTCL\Database\Touch_Events_Store;

if ( '1' !== getenv( 'CLICKTRAIL_DISPOSABLE_DB_TEST' ) || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Only run against an explicitly disposable local database.' );
}

require_once dirname( __DIR__, 2 ) . '/includes/database/class-installer.php';
require_once dirname( __DIR__, 2 ) . '/includes/database/class-touch-events-store.php';

global $wpdb;
$table = $wpdb->prefix . 'clicutcl_touch_events';
foreach ( array( 'clicutcl_events', 'clicutcl_queue', 'clicutcl_touch_events' ) as $suffix ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $suffix ) ) ) {
		throw new RuntimeException( 'Refusing to overwrite existing ClickTrail tables.' );
	}
}

$check = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};

Installer::run();
$check( 4 === (int) get_option( Installer::DB_VERSION_OPTION ), 'Fresh install did not become ready.' );

// Reconstruct the v3 table, including a historical row with no event identity.
$wpdb->query( "ALTER TABLE {$table} DROP INDEX blog_event_key, DROP COLUMN event_key, DROP COLUMN event_id" );
$wpdb->insert( $table, array( 'event_name' => 'legacy', 'created_at' => '2026-01-01 00:00:00' ) );
update_option( Installer::DB_VERSION_OPTION, 3 );
Installer::run();
$check( 4 === (int) get_option( Installer::DB_VERSION_OPTION ), 'Upgrade did not become ready.' );
$check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_id IS NULL AND event_key IS NULL" ), 'Migration lost or re-keyed legacy data.' );

$event = array(
	'event_name' => 'purchase',
	'event_id'   => 'purchase_123',
	'timestamp'  => 1700000000,
	'commerce'   => array( 'value' => 49.99, 'currency' => 'EUR' ),
);
Touch_Events_Store::record( $event, true, 1 );
$replay = array_replace( $event, array( 'commerce' => array( 'value' => 999 ) ) );
Touch_Events_Store::record( $replay, true, 1 );
$check( '' === $wpdb->last_error, 'Replay caused a database error.' );
$check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_id = 'purchase_123'" ), 'Replay inflated the ledger.' );
$check( '49.9900' === $wpdb->get_var( "SELECT amount FROM {$table} WHERE event_id = 'purchase_123'" ), 'Replay overwrote original value.' );

Touch_Events_Store::record( $event, true, 2 );
Touch_Events_Store::record( array_replace( $event, array( 'event_name' => 'refund' ) ), true, 1 );
Touch_Events_Store::record( array_replace( $event, array( 'event_id' => 'PURCHASE_123' ) ), true, 1 );
Touch_Events_Store::record( array_replace( $event, array( 'event_id' => str_repeat( 'x', 512 ) ) ), true, 1 );
$check( 6 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'Distinct event, site or case was collapsed.' );

Touch_Events_Store::record( array_replace( $event, array( 'event_id' => 'denied' ) ), false, 1 );
$check( 6 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'Denied event was persisted.' );
Touch_Events_Store::record( array_replace( $event, array( 'event_id' => '' ) ), true, 1 );
Touch_Events_Store::record( array_replace( $event, array( 'event_id' => '' ) ), true, 1 );
$check( 8 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'Unknown event identities were collapsed.' );

// An incomplete migration must not write rows without the unique index.
update_option( Installer::DB_VERSION_OPTION, 3 );
Touch_Events_Store::record( array_replace( $event, array( 'event_id' => 'upgrade-pending' ) ), true, 1 );
$check( 8 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'Incomplete schema accepted an event.' );
echo "PASS: fresh install, v3 migration, replay, isolation, consent and incomplete-schema checks.\n";
