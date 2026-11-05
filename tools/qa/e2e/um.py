"""E2E Ultimate Member registration scenarios (#174). Usage: um.py <evidence-dir>

Drives Chromium through the real Ultimate Member registration form (free plugin, pinned by um-setup.sh),
reads what ClickTrail stored on the new user (`_clicutcl_*` user meta), dispatched (capture mu-plugin) and
printed for the browser (`sign_up` follow-up event), and judges it. PASS / FAIL lines; any FAIL exits 1.
Writes <evidence-dir>/um.json (observations) and results.json.

Scenarios: S1 landing (consent not required), S2 consent required (accept, deny, undecided), S3 direct visit,
S4 hook replay, S5 wp-admin "Add user", S6 privacy lifecycle on a real WordPress, S7 WP_DEBUG_LOG.
"""
import json, pathlib, re, sys, time, urllib.parse
from playwright.sync_api import sync_playwright
from adapters_lib import BASE, pod_lines, new_context, settle, set_consent_mode, wp, wp_json

OUT = pathlib.Path(sys.argv[1])
OUT.mkdir(parents=True, exist_ok=True)
PASSWORD = 'Str0ng!Passw0rd#2026'
RUN = str(int(time.time()))[-6:]  # unique logins, so a rerun against a used pod does not hit UM's duplicate-username check
ATTRIBUTION_SETTINGS = {
    'enable_attribution': 1, 'require_consent': 0, 'cookie_days': 90, 'enable_js_injection': 1,
    'enable_link_decoration': 1, 'link_allowed_domains': 'example.test,calendly.com',
}
results = []
observed = {}


def record(status, scenario, message):
    results.append({'status': status, 'scenario': scenario, 'message': message})
    print('%-8s%s: %s' % (status, scenario, message))


def check(ok, scenario, message):
    record('PASS' if ok else 'FAIL', scenario, message)


def register_url():
    """The page UM created for its registration form."""
    return wp_json("$p = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 's' => 'ultimatemember', 'numberposts' => 20 ) ); "
                   "foreach ( $p as $page ) { if ( preg_match( '/form_id=\"(\\\\d+)\"/', $page->post_content, $m ) && 'register' === get_post_meta( (int) $m[1], '_um_mode', true ) ) { return get_permalink( $page ); } } return '';")


def set_consent(required):
    set_consent_mode(required)
    wp('option', 'update', 'clicutcl_attribution_settings', json.dumps(dict(ATTRIBUTION_SETTINGS, require_consent=1 if required else 0)), '--format=json')


def user_state(login):
    """ClickTrail's user meta and the core user for a login."""
    return wp_json(
        "$u = get_user_by( 'login', %s ); if ( ! $u ) { return null; } $meta = array(); "
        "foreach ( get_user_meta( $u->ID ) as $k => $v ) { if ( 0 === strpos( $k, '_clicutcl_' ) ) { $meta[ $k ] = $v[0]; } } "
        "return array( 'id' => (int) $u->ID, 'email' => $u->user_email, 'meta' => $meta );" % json.dumps(login)
    )


def signups(since):
    return [e for e in pod_lines('ct-capture.jsonl')[since:] if e.get('event_name') == 'sign_up']


def capture_count():
    return len(pod_lines('ct-capture.jsonl'))


def wait_attribution_cookie(ctx, timeout=10):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if any(c['name'] == 'attribution' for c in ctx.cookies()):
            return True
        time.sleep(0.25)
    return False


def register(browser, tag, landing=None, decision=None):
    """One registration through the real UM form. Returns what happened."""
    login = 'um%s%s' % (tag, RUN)
    ctx = new_context(browser)
    page = ctx.new_page()
    before = capture_count()
    set_cookie_headers = []

    def on_response(resp):
        if resp.request.method == 'POST' and '/register' in resp.url:
            set_cookie_headers.append(resp.all_headers().get('set-cookie', ''))

    batch_bodies = []

    def on_request(req):
        if req.method == 'POST' and 'events/batch' in req.url:
            batch_bodies.append(req.post_data or '')

    page.on('response', on_response)
    page.on('request', on_request)
    out = {'login': login, 'landing': landing, 'decision': decision}
    if landing:
        page.goto(BASE + landing)
        if decision:
            page.wait_for_selector('.ct-btn-primary', state='visible', timeout=10000)
            page.click('.ct-btn-primary' if decision == 'accept' else '.ct-btn-secondary')
        out['attribution_cookie'] = wait_attribution_cookie(ctx, 6 if decision != 'deny' else 2)
    page.goto(register_url())
    if decision == 'ignore':
        page.wait_for_selector('.ct-btn-primary', state='visible', timeout=10000)
    page.fill('input[name^="user_login"]', login)
    page.fill('input[name^="user_email"]', '%s@example.test' % login)
    page.fill('input[name^="user_password"]', PASSWORD)
    confirm = page.locator('input[name^="confirm_user_password"]')
    if confirm.count():
        confirm.first.fill(PASSWORD)
    page.click('input[type="submit"].um-button, #um-submit-btn')
    try:
        page.wait_for_url(lambda url: '/register' not in url, timeout=25000)
    except Exception:
        out['stuck_on_form'] = (page.inner_text('.um-notice, .um-field-error, .um-registration-form') or '')[:300]
    page.wait_for_load_state('load')
    settle(4)  # the browser event transport batches after load
    html = page.content()
    state = user_state(login)
    out['user'] = state
    out['html_event_ids'] = sorted(set(re.findall(r'um_registration_\d+', html)))
    cookie_value = urllib.parse.unquote(' '.join(set_cookie_headers))
    out['set_cookie_event_ids'] = sorted(set(re.findall(r'um_registration_\d+', cookie_value)))
    out['set_cookie_has_signup'] = 'ct_event_signup' in ' '.join(set_cookie_headers)
    out['batch_sign_up_ids'] = sorted({m for body in batch_bodies for m in re.findall(r'"event_id":\s*"(um_registration_\d+)"', body)})
    out['capture_after'] = pod_lines('ct-capture.jsonl')[before:]
    out['signups'] = [e for e in out['capture_after'] if e.get('event_name') == 'sign_up']
    ctx.close()
    return out


def landing_scenario(browser):
    res = register(browser, 'landing', '/?utm_source=test&utm_medium=cpc&utm_campaign=um&gclid=GCLIDUM1')
    observed['S1_landing'] = res
    user = res['user']
    s = 'S1 landing'
    check(user is not None, s, 'the UM registration created a user (%s)' % (res.get('stuck_on_form') or 'redirected'))
    if user is None:
        return
    meta = user['meta']
    expect = {'_clicutcl_gclid': 'GCLIDUM1', '_clicutcl_lt_source': 'test', '_clicutcl_lt_medium': 'cpc', '_clicutcl_lt_campaign': 'um', '_clicutcl_ft_source': 'test'}
    check(all(meta.get(k) == v for k, v in expect.items()), s, 'user meta holds the landing attribution %s (got %s)' % (expect, {k: meta.get(k) for k in expect}))
    check(meta.get('_clicutcl_um_signup_sent') == 'yes', s, 'sent marker is set')
    check(bool(meta.get('_clicutcl_visitor_id')), s, 'visitor id stored (%s)' % meta.get('_clicutcl_visitor_id'))
    check(not any(k.startswith('_clicutcl_') and k not in allowed_meta_keys() for k in meta), s, 'only allowlisted keys stored (%s)' % sorted(set(meta) - set(allowed_meta_keys())))
    check(PASSWORD not in json.dumps(meta), s, 'no password in ClickTrail user meta')
    event_id = 'um_registration_%d' % user['id']
    sign_ups = res['signups']
    check(len(sign_ups) == 1, s, 'exactly one server-side sign_up dispatched across the server call and the browser transport (%d)' % len(sign_ups))
    if sign_ups:
        check(sign_ups[0].get('event_id') == event_id, s, 'dispatched event_id is %s (%s)' % (event_id, sign_ups[0].get('event_id')))
        check((sign_ups[0].get('attribution') or {}).get('gclid') == 'GCLIDUM1', s, 'dispatched sign_up carries gclid')
    check(res['set_cookie_has_signup'] and res['set_cookie_event_ids'] == [event_id], s, 'core ct_event_signup cookie carries the same event id (%s)' % res['set_cookie_event_ids'])
    check(event_id in res['html_event_ids'], s, 'the page printed after registration pushes the same event id to the browser (%s)' % res['html_event_ids'])
    record('PASS' if event_id in res['batch_sign_up_ids'] else 'INFO', s, 'the browser events route was sent the same event id (%s), so the one dispatch above is the dedupe of both copies' % res['batch_sign_up_ids'])
    observed['S1_user'] = user


def allowed_meta_keys():
    return wp_json("return array_map( 'strval', CLICUTCL\\Privacy\\User_Registration_Privacy::managed_keys() );")


def consent_scenarios(browser):
    set_consent(True)
    try:
        for decision in ('accept', 'deny', 'ignore'):
            res = register(browser, 'c' + decision[:3], '/?utm_source=consent&gclid=GCLIDCONSENT', decision)
            observed['S2_' + decision] = res
            s = 'S2 consent ' + decision
            user = res['user']
            check(user is not None, s, 'registration itself still succeeds (%s)' % (res.get('stuck_on_form') or 'redirected'))
            if user is None:
                continue
            meta = user['meta']
            if decision == 'accept':
                check(meta.get('_clicutcl_gclid') == 'GCLIDCONSENT', s, 'attribution stored after consent (%s)' % meta.get('_clicutcl_gclid'))
                check(len(res['signups']) == 1, s, 'one sign_up dispatched (%d)' % len(res['signups']))
                check(res['set_cookie_event_ids'] == ['um_registration_%d' % user['id']], s, 'core signup cookie shares the event id')
            else:
                check(len(meta) == 0, s, 'no ClickTrail user meta without marketing consent (%s)' % sorted(meta))
                check(res['signups'] == [], s, 'no server-side sign_up without marketing consent (%d)' % len(res['signups']))
                check(not res['set_cookie_has_signup'], s, 'no core ct_event_signup cookie without marketing consent')
                check(res['html_event_ids'] == [], s, 'nothing printed for the browser without marketing consent')
    finally:
        set_consent(False)


def direct_scenario(browser):
    res = register(browser, 'direct')
    observed['S3_direct'] = res
    user = res['user']
    s = 'S3 direct'
    check(user is not None, s, 'registration succeeded')
    if user is None:
        return
    check(set(user['meta']) == {'_clicutcl_um_signup_sent'}, s, 'only the sent marker is stored without an attribution cookie (%s)' % sorted(user['meta']))
    check(len(res['signups']) == 1 and res['signups'][0].get('event_id') == 'um_registration_%d' % user['id'], s, 'one sign_up with the deterministic id (%d)' % len(res['signups']))


def replay_scenario():
    user = observed.get('S1_user')
    s = 'S4 replay'
    if not user:
        record('FAIL', s, 'needs the S1 user')
        return
    before = capture_count()
    meta_before = user_state(observed['S1_landing']['login'])['meta']
    wp('eval', "do_action( 'um_registration_complete', %d, array(), array( 'form_id' => 1, 'mode' => 'register' ) ); do_action( 'um_registration_complete', %d, array(), array( 'form_id' => 1, 'mode' => 'register' ) );" % (user['id'], user['id']))
    settle(2)
    check(signups(before) == [], s, 'replaying um_registration_complete twice dispatches nothing more')
    check(user_state(observed['S1_landing']['login'])['meta'] == meta_before, s, 'replay leaves the stored meta unchanged')


def admin_add_user_scenario(browser):
    s = 'S5 wp-admin Add user'
    ctx = new_context(browser)
    page = ctx.new_page()
    before = capture_count()
    page.goto(BASE + '/wp-login.php?utm_source=admin&gclid=GCLIDADMIN')
    page.fill('#user_login', 'admin')
    page.fill('#user_pass', 'admin')
    page.click('#wp-submit')
    page.wait_for_load_state('load')
    page.goto(BASE + '/wp-admin/user-new.php')
    page.fill('#user_login', 'umadmin' + RUN)
    page.fill('#email', 'umadmin%s@example.test' % RUN)
    page.fill('#pass1', PASSWORD)
    if page.locator('#pw_weak').count() and page.locator('#pw_weak').is_visible():
        page.check('#pw_weak')
    page.click('#createusersub')
    page.wait_for_load_state('load')
    settle(2)
    user = user_state('umadmin' + RUN)
    observed['S5_admin'] = {'user': user, 'signups': signups(before)}
    ctx.close()
    check(user is not None, s, 'wp-admin created the user')
    if user is None:
        return
    check(len(user['meta']) == 0, s, 'a user added from wp-admin gets no registration attribution (%s)' % sorted(user['meta']))
    check(signups(before) == [], s, 'and no server-side sign_up')


def privacy_scenario():
    s = 'S6 privacy'
    user = observed.get('S1_user')
    if not user:
        record('FAIL', s, 'needs the S1 user')
        return
    email = user['email']
    exported = wp_json(
        "$ex = apply_filters( 'wp_privacy_personal_data_exporters', array() ); $e = $ex['clicutcl-user-registration'] ?? null; if ( ! $e ) { return null; } "
        "$r = call_user_func( $e['callback'], %s, 1 ); $keys = array(); foreach ( $r['data'] as $item ) { $keys[ $item['data'][0]['value'] ] = $item['data'][1]['value']; } return array( 'done' => $r['done'], 'items' => $keys );" % json.dumps(email)
    )
    check(exported is not None and exported['done'], s, 'wp_privacy_personal_data_exporters lists the registration exporter')
    items = (exported or {}).get('items', {})
    check(items.get('_clicutcl_gclid') == 'GCLIDUM1' and items.get('_clicutcl_um_signup_sent') == 'yes', s, 'export returns the stored attribution (%s)' % sorted(items))
    check(all(k.startswith('_clicutcl_') for k in items), s, 'export carries ClickTrail keys only (%s)' % sorted(items))
    wp('eval', "update_user_meta( %d, '_clicutcl_other_plugin', 'lookalike' );" % user['id'])
    erased = wp_json(
        "$er = apply_filters( 'wp_privacy_personal_data_erasers', array() ); $e = $er['clicutcl-user-registration'] ?? null; if ( ! $e ) { return null; } "
        "$r = call_user_func( $e['callback'], %s, 1 ); $left = array(); foreach ( get_user_meta( %d ) as $k => $v ) { if ( 0 === strpos( $k, '_clicutcl_' ) ) { $left[] = $k; } } "
        "return array( 'removed' => $r['items_removed'], 'retained' => $r['items_retained'], 'done' => $r['done'], 'left' => $left, 'login' => get_userdata( %d )->user_login );" % (json.dumps(email), user['id'], user['id'])
    )
    check(erased is not None and erased['removed'] and not erased['retained'] and erased['done'], s, 'wp_privacy_personal_data_erasers erases the registration meta (%s)' % erased)
    check(erased is not None and erased['left'] == ['_clicutcl_other_plugin'], s, 'only the allowlisted keys went; a lookalike key and the user stay (%s)' % (erased or {}).get('left'))
    # Retention and purge-all through the real user query (date_query + anchored key regexp).
    php = (
        "$mk = function ( $login, $registered ) { $id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'user_registered' => $registered ) ); "
        "update_user_meta( $id, '_clicutcl_gclid', 'X' ); update_user_meta( $id, '_clicutcl_um_signup_sent', 'yes' ); update_user_meta( $id, '_clicutcl_other_plugin', 'keep' ); return $id; }; "
        "$old = $mk( 'umpurgeold%s', gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) ); $new = $mk( 'umpurgenew%s', gmdate( 'Y-m-d H:i:s' ) ); "
        "$p = new CLICUTCL\\Privacy\\User_Registration_Privacy(); $exp = $p->purge_expired_user_metadata( 90 ); "
        "$state = function ( $id ) { return array( 'gclid' => get_user_meta( $id, '_clicutcl_gclid', true ), 'other' => get_user_meta( $id, '_clicutcl_other_plugin', true ) ); }; "
        "$after_expired = array( 'old' => $state( $old ), 'new' => $state( $new ) ); $all = $p->purge_all_user_metadata( 1 ); "
        "return array( 'expired' => $exp, 'after_expired' => $after_expired, 'all' => $all, 'after_all_new' => $state( $new ) );"
    ) % (RUN, RUN)
    lifecycle = wp_json(php)
    check(lifecycle['expired']['removed'] == 1 and lifecycle['after_expired']['old']['gclid'] == '' and lifecycle['after_expired']['new']['gclid'] == 'X', s, 'retention purge clears the user registered 200 days ago and keeps the new one (%s)' % lifecycle['expired'])
    check(lifecycle['after_expired']['old']['other'] == 'keep', s, 'retention purge leaves unrelated meta')
    check(lifecycle['all']['removed'] >= 1 and lifecycle['after_all_new']['gclid'] == '' and lifecycle['after_all_new']['other'] == 'keep', s, 'purge-all (diagnostics purge / uninstall) clears the remaining user in pages of one (%s)' % lifecycle['all'])
    observed['S6_lifecycle'] = {'exported': exported, 'erased': erased, 'lifecycle': lifecycle}


def debug_log_scenario():
    s = 'S7 debug log'
    import subprocess
    proc = subprocess.run(['podman', 'exec', 'ct-e2e-wp', 'cat', '/var/www/html/wp-content/debug.log'], capture_output=True, text=True)
    (OUT / 'debug.log').write_text(proc.stdout)
    ours = [line for line in proc.stdout.splitlines() if 'click-trail-handler' in line and re.search(r'PHP (Warning|Notice|Deprecated|Fatal|Parse)', line)]
    check(ours == [], s, 'no PHP warning, notice, deprecation or fatal from the plugin in WP_DEBUG_LOG (%d)' % len(ours))
    for line in ours[:5]:
        print('         ' + line[:300])


def main():
    set_consent(False)
    with sync_playwright() as p:
        browser = p.chromium.launch(args=['--disable-blink-features=AutomationControlled'])
        landing_scenario(browser)
        direct_scenario(browser)
        replay_scenario()
        admin_add_user_scenario(browser)
        consent_scenarios(browser)
        browser.close()
    privacy_scenario()
    debug_log_scenario()
    (OUT / 'um.json').write_text(json.dumps(observed, indent=1, default=str))
    (OUT / 'results.json').write_text(json.dumps(results, indent=1))
    failures = [r for r in results if r['status'] == 'FAIL']
    print('%d checks, %d FAIL' % (len(results), len(failures)))
    sys.exit(1 if failures else 0)


main()
