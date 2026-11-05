"""E2E WooCommerce privacy lifecycle. Usage: woo_privacy.py <evidence-dir>

Real WooCommerce, real Woo_Order_Privacy, driven through wp-cli. For each order storage (HPOS, posts) it creates
orders through the WooCommerce CRUD API and runs export, erase, retention and purge, recording what each returns,
any database error, and the SQL WooCommerce emitted for the managed-meta query. Writes woo-privacy.json
(observations only; verify_woo_privacy.py judges them).
"""
import json, pathlib, sys
from adapters_lib import wp_json

OUT = pathlib.Path(sys.argv[1])
OUT.mkdir(parents=True, exist_ok=True)

SETUP = r"""
$mode = '%(mode)s';
global $wpdb; $wpdb->suppress_errors( true );
// Start every step from an empty order store in both storages (plain SQL: the active storage hides the other).
foreach ( array( 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}$t" ); }
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' )" );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'shop_order'" );
wp_cache_flush();
add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
update_option( 'woocommerce_custom_orders_table_enabled', 'hpos' === $mode ? 'yes' : 'no' );
$kind = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController' )->custom_orders_table_usage_is_enabled();
$mk = function ( $email, $age_days, $meta ) {
	$o = new WC_Order();
	$o->set_billing_email( $email );
	$o->set_total( 12 );
	$o->set_status( 'completed' );
	$o->set_date_created( time() - $age_days * DAY_IN_SECONDS );
	foreach ( $meta as $k => $v ) { $o->update_meta_data( $k, $v ); }
	$o->update_meta_data( '_unrelated_keep', 'keep' );
	return $o->save();
};
$full = array( '_clicutcl_consent' => '{"marketing":true}', '_clicutcl_tracking_sent' => 'yes', '_clicutcl_gclid' => 'g1', '_clicutcl_ft_source' => 'google', '_clicutcl_lt_medium' => 'cpc', '_clicutcl_visitor_id' => 'v1', '_clicutcl_session_id' => 's1' );
$ids = array(
	'old'    => $mk( 'subject@example.test', 200, $full ),
	'recent' => $mk( 'subject@example.test', 1, $full ),
	'other'  => $mk( 'other@example.test', 200, $full ),
	'bare'   => $mk( 'subject@example.test', 200, array() ),
);
return array( 'hpos_active' => (bool) $kind, 'ids' => $ids );
"""

OBSERVE = r"""
$ids = %(ids)s;
$out = array();
foreach ( $ids as $name => $id ) {
	$o = wc_get_order( $id );
	$keys = array();
	foreach ( $o->get_meta_data() as $m ) { $keys[] = $m->key; }
	sort( $keys );
	$out[ $name ] = $keys;
}
return $out;
"""

CALL = r"""
global $wpdb;
$wpdb->suppress_errors( true );
$wpdb->last_error = '';
$sqls = array();
add_filter( 'query', function ( $sql ) use ( &$sqls ) { if ( false !== strpos( $sql, '_clicutcl_' ) && false !== stripos( $sql, 'JOIN' ) ) { $sqls[] = $sql; } return $sql; } );
$priv = new CLICUTCL\Privacy\Woo_Order_Privacy();
$res = %(call)s;
$err = $wpdb->last_error;
$max_joins = 0; foreach ( $sqls as $s ) { $max_joins = max( $max_joins, substr_count( strtoupper( $s ), ' JOIN ' ) ); }
return array( 'result' => $res, 'db_error' => $err, 'meta_join_queries' => count( $sqls ), 'max_joins' => $max_joins );
"""

CALLS = {
    'clause_count': "count( array_filter( CLICUTCL\\Privacy\\Woo_Order_Privacy::managed_meta_query(), 'is_array' ) )",
    'export': "$priv->export_order_data( 'subject@example.test' )",
    'erase': "$priv->erase_order_data( 'subject@example.test' )",
    'retention': "$priv->purge_expired_order_metadata( 90 )",
    'purge': "$priv->purge_all_order_metadata()",
    'purge_uninstall': "$priv->purge_all_order_metadata( CLICUTCL\\Privacy\\Woo_Order_Privacy::RETENTION_BATCH_SIZE, true )",
}


def py_ids(ids):
    return 'array(' + ','.join("'%s'=>%d" % (k, v) for k, v in ids.items()) + ')'


def run(mode):
    """Each lifecycle step starts from freshly seeded orders so it is observed in isolation."""
    rec = {'mode': mode, 'steps': {}}
    rec['clauses'] = wp_json('return %s;' % CALLS['clause_count'])
    for step in ('export', 'retention', 'erase', 'purge', 'purge_uninstall'):
        state = wp_json(SETUP % {'mode': mode})
        ids = state['ids']
        rec['hpos_active'] = state['hpos_active']
        entry = {'ids': ids, 'before': wp_json(OBSERVE % {'ids': py_ids(ids)})}
        entry.update(wp_json(CALL % {'call': CALLS[step]}))
        entry['after'] = wp_json(OBSERVE % {'ids': py_ids(ids)})
        rec['steps'][step] = entry
    return rec


data = {'scenarios': [run('hpos'), run('posts')]}
(OUT / 'woo-privacy.json').write_text(json.dumps({'scenarios': data['scenarios']}, indent=2))
print(json.dumps(data, indent=1)[:6000])
