"""E2E Easy Digital Downloads scenarios. Usage: edd.py <evidence-dir> [scenario ...]

Real EDD (Store/manual gateway) in the shared pod. Writes <evidence-dir>/edd.json (observations only;
verify_edd.py judges them). Scenarios: landing (incl. receipt reloads), consent_denied, consent_accepted, consent_withdrawn,
privacy. Consent-off scenarios run first; the consent ones switch Consent Mode on.
"""
import json, pathlib, re, sys, time, urllib.parse
from playwright.sync_api import sync_playwright
from adapters_lib import BASE, MARK, new_context, pod_lines, set_consent_mode, settle, wp, wp_json

OUT = pathlib.Path(sys.argv[1])
OUT.mkdir(parents=True, exist_ok=True)
SELECTED = sys.argv[2:]
DENIED = json.dumps({'marketing': False, 'analytics': False})
FIXTURE = {}


def fixture():
    if not FIXTURE:
        FIXTURE.update(wp_json(
            "$q = new WP_Query( array( 'post_type' => 'download', 'posts_per_page' => 1, 'fields' => 'ids' ) ); "
            "return array( 'download_id' => (int) $q->posts[0], 'checkout' => edd_get_checkout_uri(), 'success' => edd_get_success_page_uri(), 'edd' => EDD_VERSION );"
        ))
    return FIXTURE


def last_order_id():
    return wp_json("$ids = edd_get_orders( array( 'number' => 1, 'orderby' => 'id', 'order' => 'DESC', 'fields' => 'ids' ) ); return $ids ? (int) $ids[0] : 0;")


def orders_after(order_id):
    return wp_json(
        "$out = array(); foreach ( edd_get_orders( array( 'number' => 100, 'orderby' => 'id', 'order' => 'ASC' ) ) as $o ) { if ( $o->id <= %d ) { continue; } "
        "$meta = array(); foreach ( edd_get_order_meta( $o->id ) as $k => $v ) { if ( 0 === strpos( $k, '_clicutcl_' ) ) { $meta[ $k ] = is_array( $v ) ? $v[0] : $v; } } "
        "$out[] = array( 'id' => (int) $o->id, 'type' => $o->type, 'status' => $o->status, 'email' => $o->email, 'total' => (float) $o->total, 'currency' => $o->currency, 'number' => (string) $o->get_number(), 'meta' => $meta ); } return $out;" % order_id
    )


def purchases():
    return [e for e in pod_lines('ct-capture.jsonl') if e.get('event_name') == 'purchase']


def purchase_pushes(page):
    return page.evaluate("() => (window.dataLayer || []).filter((e) => e && e.event === 'purchase')")


def accept_or_deny(page, decision):
    page.wait_for_selector('.ct-btn-primary', state='visible', timeout=10000)
    page.click('.ct-btn-primary' if decision == 'accept' else '.ct-btn-secondary')
    settle()


def buy(page, tag, dump):
    """Add the download, check out through the Store gateway and land on the receipt; returns the receipt URL."""
    fx = fixture()
    page.goto(BASE + '/?edd_action=add_to_cart&download_id=%d' % fx['download_id'])
    page.goto(fx['checkout'])
    page.wait_for_selector('#edd_purchase_form', timeout=15000)
    page.fill('input[name="edd_email"]', '%s@example.test' % tag)
    page.fill('input[name="edd_first"]', 'E2E')
    page.fill('input[name="edd_last"]', tag)
    success_path = urllib.parse.urlparse(fx['success']).path
    try:
        with page.expect_navigation(url=re.compile(re.escape(success_path)), timeout=30000):
            page.click('#edd-purchase-button')
    except Exception:
        (OUT / ('checkout-failure-%s.html' % tag)).write_text(page.content())
        raise
    page.wait_for_load_state('networkidle')
    settle()
    return page.url


def run_purchase(browser, tag, landing, consent=None, withdraw=False):
    ctx = new_context(browser)
    page = ctx.new_page()
    before = last_order_id()
    sent_before = len(purchases())
    page.goto(BASE + '/' + landing)
    if consent:
        accept_or_deny(page, consent)
    page.wait_for_load_state('networkidle')
    settle()
    cookies = {c['name']: urllib.parse.unquote(c['value']) for c in ctx.cookies()}
    if withdraw:
        # Withdrawal after the click ID was captured: the attribution cookie stays, the consent cookie flips.
        ctx.add_cookies([{'name': n, 'value': urllib.parse.quote(DENIED), 'url': BASE} for n in cookies if n.startswith('ct_consent') and n != 'ct_consent_updated'])
    receipt_url = buy(page, tag, True)
    pushes = purchase_pushes(page)
    receipt_html = page.content()
    reloads = []
    for _ in range(2):
        page.reload()
        page.wait_for_load_state('networkidle')
        settle()
        reloads.append({'purchase_pushes': len(purchase_pushes(page)), 'event_ids_in_html': re.findall(r'purchase_edd_\d+', page.content())})
    orders = orders_after(before)
    result = {
        'receipt_url': receipt_url,
        'orders': orders,
        'attribution_cookie': cookies.get('attribution', ''),
        'consent_cookies': {k: v for k, v in cookies.items() if k.startswith('ct_consent')},
        'pushes': pushes,
        'receipt_has_event_id': bool(re.search(r'purchase_edd_\d+', receipt_html)),
        'reloads': reloads,
        'dispatched': purchases()[sent_before:],
        'edd': fixture()['edd'],
    }
    ctx.close()
    return result


def landing(browser):
    set_consent_mode(False)
    return run_purchase(browser, 'landing', '?gclid=EDDGCLID&utm_source=google&utm_medium=cpc&utm_campaign=edd-launch')


def consent_denied(browser):
    set_consent_mode(True)
    return run_purchase(browser, 'denied', '?gclid=DENIEDGCLID&utm_source=google&utm_medium=cpc', consent='deny')


def consent_accepted(browser):
    set_consent_mode(True)
    return run_purchase(browser, 'accepted', '?gclid=ACCEPTEDGCLID&utm_source=google&utm_medium=cpc&utm_campaign=accepted', consent='accept')


def consent_withdrawn(browser):
    set_consent_mode(True)
    return run_purchase(browser, 'withdrawn', '?gclid=WITHDRAWNGCLID&utm_source=google&utm_medium=cpc', consent='accept', withdraw=True)


def privacy(browser):
    """Export, erase, retention and manual purge against real EDD orders from the earlier scenarios."""
    set_consent_mode(False)
    php = (
        "$h = new CLICUTCL\\Privacy\\Privacy_Handler(); $p = new CLICUTCL\\Privacy\\Edd_Order_Privacy(); $r = array(); "
        "$keys = function ( $id ) { $o = array(); foreach ( edd_get_order_meta( $id ) as $k => $v ) { if ( 0 === strpos( $k, '_clicutcl_' ) && '_clicutcl_tracking_sent' !== $k ) { $o[] = $k; } } sort( $o ); return $o; }; "
        "$sent = function ( $id ) { return edd_get_order_meta( $id, '_clicutcl_tracking_sent', true ); }; "
        "$foreign = function ( $id ) { $o = array(); foreach ( edd_get_order_meta( $id ) as $k => $v ) { if ( 0 !== strpos( $k, '_clicutcl_' ) ) { $o[] = $k; } } sort( $o ); return $o; }; "
        "$ids = array(); foreach ( array( 'landing', 'accepted' ) as $t ) { $o = edd_get_orders( array( 'email' => $t . '@example.test', 'number' => 1 ) ); $ids[ $t ] = $o ? (int) $o[0]->id : 0; } "
        "$r['ids'] = $ids; "
        # export through the registered WordPress exporter
        "$exp = $h->export_user_data( 'landing@example.test', 1 ); "
        "$r['export_items'] = count( array_filter( $exp['data'], function ( $i ) { return 'clicutcl_edd_orders' === ( $i['group_id'] ?? '' ); } ) ); "
        "$r['export_has_gclid'] = (bool) array_filter( $exp['data'], function ( $i ) { return false !== strpos( $i['item_id'] ?? '', '_clicutcl_gclid' ); } ); "
        "$r['export_done'] = $exp['done']; "
        # retention: a fresh order is kept, an aged one is purged, foreign meta stays
        "$r['landing_keys_before'] = $keys( $ids['landing'] ); $r['landing_foreign_before'] = $foreign( $ids['landing'] ); "
        "$fresh = $p->purge_expired_order_metadata( 90 ); $r['retention_fresh'] = $fresh; $r['landing_keys_after_fresh'] = $keys( $ids['landing'] ); "
        "edd_update_order( $ids['landing'], array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) ) ); "
        "$aged = $p->purge_expired_order_metadata( 90 ); $r['retention_aged'] = $aged; $r['landing_keys_after_aged'] = $keys( $ids['landing'] ); $r['landing_foreign_after'] = $foreign( $ids['landing'] ); $r['landing_sent_after_aged'] = $sent( $ids['landing'] ); "
        # eraser through the registered WordPress eraser
        "$r['accepted_keys_before'] = $keys( $ids['accepted'] ); $er = $h->erase_user_data( 'accepted@example.test', 1 ); $r['erase'] = $er; $r['accepted_keys_after'] = $keys( $ids['accepted'] ); $r['accepted_foreign_after'] = $foreign( $ids['accepted'] ); $r['accepted_sent_after_erase'] = $sent( $ids['accepted'] ); "
        # manual purge
        "edd_update_order_meta( $ids['accepted'], '_clicutcl_gclid', 'PURGE-ME' ); edd_update_order_meta( $ids['landing'], '_clicutcl_lt_source', 'PURGE-ME' ); "
        "$purge = $p->purge_all_order_metadata(); $r['purge_all'] = $purge; $r['keys_after_purge'] = array( $keys( $ids['accepted'] ), $keys( $ids['landing'] ) ); $r['sent_after_purge'] = array( $sent( $ids['accepted'] ), $sent( $ids['landing'] ) ); "
        "return $r;"
    )
    return wp_json(php)


SCENARIOS = {
    'landing': landing, 'consent_denied': consent_denied, 'consent_accepted': consent_accepted,
    'consent_withdrawn': consent_withdrawn, 'privacy': privacy,
}

if __name__ == '__main__':
    set_consent_mode(False)
    result = {'scenarios': {}}
    with sync_playwright() as p:
        browser = p.chromium.launch()
        result['browser'] = 'Chromium ' + browser.version
        for name in (SELECTED or SCENARIOS):
            print('scenario', name, flush=True)
            result['scenarios'][name] = SCENARIOS[name](browser)
            (OUT / 'edd.json').write_text(json.dumps(result, indent=1))
        browser.close()
    (OUT / 'edd.json').write_text(json.dumps(result, indent=1))
    print('wrote', OUT / 'edd.json')
