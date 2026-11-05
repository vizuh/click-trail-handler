#!/usr/bin/env bash
# Attribution cookie server re-issue proof (#141, specs/009-itp-cookie-reissue SC-003). Usage: cookie-reissue.sh [plugin-root]
# Own pod (setup.sh), one WordPress served as www.example.test / example.test, Chromium through Playwright. Exit 1 on any mismatch.
# Run it under `flock /tmp/ct-e2e.lock` when other agents share the pod. S8 (page responses carry no Set-Cookie) is checked by adapters-ci.sh.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export CT_E2E_PLUGIN="${1:-${CT_E2E_PLUGIN:-$(cd "$HERE/../../.." && pwd)}}"
OUT="${CT_E2E_OUT:-$(mktemp -d)}"
mkdir -p "$OUT"
cleanup() {
  podman pod rm -f ct-e2e >/dev/null 2>&1 || true
  podman volume rm -f ct-e2e-wp ct-e2e-db >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$HERE/setup.sh"
"$HERE/wp.sh" option update ct_e2e_dynamic_host 1 >/dev/null
python3 "$HERE/cookie_reissue.py" > "$OUT/cookie-reissue.json"
python3 - "$OUT/cookie-reissue.json" <<'PY'
import json, sys, urllib.parse
d = json.load(open(sys.argv[1]))
fail = 0
def check(ok, label):
    global fail
    print(('ok    ' if ok else 'FAIL  ') + label)
    fail += 0 if ok else 1
enc = d['expected_value_encoded']
check(d['token_present'], 'the landing page embeds the client token when the setting is on')

# Page responses: never a Set-Cookie (S8 rule), even when a valid attribution cookie and consent arrive.
for state, rows in d['pages'].items():
    check(all(r['status'] == 200 and r['set_cookie'] == [] for r in rows), 'page GET/HEAD/home with %s: no Set-Cookie (%s)' % (state, [r['set_cookie'] for r in rows]))

# The route on the wire.
r = d['routes']
expected = 'attribution=%s; Max-Age=7776000; Path=/; Domain=example.test; SameSite=Lax' % enc
check(r['valid']['status'] == 200 and r['valid']['set_cookie'] == [expected], 'valid cookie: exactly one Set-Cookie, Max-Age=cookie_days*86400, Domain=example.test (%s)' % r['valid']['set_cookie'])
check(r['valid']['json'] == {'success': True, 'reissued': True}, 'valid cookie: body reissued=true')
check('HttpOnly' not in r['valid']['set_cookie'][0] and 'Secure' not in r['valid']['set_cookie'][0], 'no HttpOnly (script must read it); no Secure over http')
check(r['valid']['set_cookie'][0].split(';')[0].split('=', 1)[1] == enc, 'the value is the request value, byte for byte (encodeURIComponent form)')
check(r['valid']['cache_control'] == ['no-store, private'], 'Cache-Control: no-store, private on the response that sets a cookie (%s)' % r['valid']['cache_control'])
check(r['valid_apex']['set_cookie'] == [expected], 'apex host: same Domain=example.test')
no_domain = expected.replace('; Domain=example.test', '')
for name in ('host_only_claim', 'foreign_domain_claim', 'no_domain_claim'):
    check(r[name]['set_cookie'] == [no_domain], '%s: the script did not apply the scope domain, so the server sends a host-only cookie (%s)' % (name, [c.split('; ')[2:] for c in r[name]['set_cookie']]))
check(r['expires_in']['set_cookie'] == [expected.replace('Max-Age=7776000', 'Max-Age=3600')], 'expires_in=3600 (a restored cookie) caps Max-Age at 3600')
for name in ('no_cookie', 'legacy_name_only', 'invalid_json', 'nested', 'oversized'):
    check(r[name]['status'] == 200 and r[name]['set_cookie'] == [] and r[name]['json']['reissued'] is False, '%s: 200, reissued=false (%s), no Set-Cookie' % (name, r[name]['json'].get('reason')))
for name, status in (('no_token', 401), ('bad_token', 401), ('get_not_allowed', 404)):
    check(r[name]['status'] == status and r[name]['set_cookie'] == [], '%s: HTTP %d, no Set-Cookie (got %d)' % (name, status, r[name]['status']))

c = d['consent_routes']
check(c['none']['status'] == 403 and c['none']['json']['code'] == 'consent_required' and c['none']['set_cookie'] == [], 'consent required, no consent cookie: 403, no Set-Cookie')
check(c['denied']['status'] == 403 and c['denied']['set_cookie'] == [], 'consent required, denied: 403, no Set-Cookie')
check(c['granted']['status'] == 200 and len(c['granted']['set_cookie']) == 1, 'consent required, granted: 200, one Set-Cookie')
s = d['setting_off']
check(s['status'] == 403 and s['code'] == 'cookie_reissue_disabled' and s['set_cookie'] == [] and s['config_url'] == '', 'setting off: route 403, no URL given to the script (url=%r)' % s['config_url'])

# Real browser round trip. The browser caps script-written attribution cookies at 24 hours (what Safari does, emulated); only an
# HTTP-set cookie can reach 90 days, so a jar expiry of about 90 days proves Chromium applied the server's Set-Cookie.
near = lambda a, b: abs(a - b) < 300
for key, domain, with_domain in (('browser_registrable', '.example.test', True), ('browser_host', 'www.example.test', False)):
    b = d[key]
    tag = b['scope']
    land, second, restore = b['landing'], b['second'], b['restore']
    check(land['document_set_cookie'] == [] and second['document_set_cookie'] == [] and restore['document_set_cookie'] == [], '%s: no Set-Cookie on any page document (S8 rule)' % tag)
    check(len(land['refreshes']) == 1 and land['refreshes'][0]['method'] == 'POST' and land['refreshes'][0]['status'] == 200 and land['refreshes'][0]['request_cookie'], '%s: the landing sends exactly one POST cookie-refresh carrying the script-written cookie, 200' % tag)
    headers = land['refreshes'][0]['headers'] if land['refreshes'] else []
    sc = [h for h in headers if h.lower().startswith('set-cookie:')]
    check(len(sc) == 1 and sc[0].startswith('Set-Cookie: attribution=') and '; Max-Age=7776000;' in sc[0] and ('Domain=example.test' in sc[0]) == with_domain and 'SameSite=Lax' in sc[0] and 'HttpOnly' not in sc[0], '%s: the server response carries one attribution Set-Cookie, Max-Age=7776000, Domain %s (%s)' % (tag, 'set' if with_domain else 'absent', sc))
    lc = land['cookies']
    check(len(lc) == 1 and lc[0]['domain'] == domain and lc[0]['path'] == '/' and lc[0]['httpOnly'] is False and lc[0]['sameSite'] == 'Lax', '%s: exactly one attribution cookie in the browser, domain %s, not HttpOnly, SameSite Lax (%s)' % (tag, domain, lc))
    check(near(lc[0]['expires'], land['expected_expires']), '%s: cookie lifetime on the wire is cookie_days (90 days, %.0f s off) although the script write was capped to 24 h' % (tag, lc[0]['expires'] - land['expected_expires']))
    check(land['marker'] is None, '%s: the pending marker is cleared after the server answered' % tag)
    check(second['refreshes'] == [] and len(second['cookies']) == 1 and near(second['cookies'][0]['expires'], land['expected_expires']), '%s: a second page view sends no request (no marker), keeps one cookie, lifetime unchanged' % tag)
    check(b['after_clear_cookies'] == [] and second['mirror'], '%s: precondition: cookie gone, localStorage mirror kept' % tag)
    check(len(restore['cookies']) == 1 and restore['cookies'][0]['domain'] == domain, '%s: the cookie is restored from the mirror: one cookie, same domain attribute (%s)' % (tag, restore['cookies']))
    check(len(restore['refreshes']) == 1 and restore['refreshes'][0]['status'] == 200 and len([h for h in restore['refreshes'][0]['headers'] if h.lower().startswith('set-cookie:')]) == 1, '%s: the restore is followed by one re-issue request, 200, one Set-Cookie' % tag)
    restore_sc = [h for h in restore['refreshes'][0]['headers'] if h.lower().startswith('set-cookie:')][0]
    restore_age = int(restore_sc.split('Max-Age=')[1].split(';')[0])
    check(7776000 - 600 < restore_age <= 7776000, '%s: the restore re-issue sends the mirror\'s remaining lifetime, not a fresh retention (Max-Age=%d)' % (tag, restore_age))
    check(near(restore['cookies'][0]['expires'], restore['expected_expires']), '%s: the restored and re-issued cookie lives cookie_days again (%.0f s off)' % (tag, restore['cookies'][0]['expires'] - restore['expected_expires']))

o = d['browser_reissue_off']
check(o['landing']['refreshes'] == [] and o['restore']['refreshes'] == [], 'control (setting off): no request on landing or restore')
check(len(o['landing']['cookies']) == 1 and near(o['landing']['cookies'][0]['expires'], o['landing']['capped_expires']), 'control (setting off): the script-written cookie stays capped at 24 h (%.0f s off)' % (o['landing']['cookies'][0]['expires'] - o['landing']['capped_expires']))
check(len(o['restore']['cookies']) == 1 and near(o['restore']['cookies'][0]['expires'], o['restore']['capped_expires']), 'control (setting off): the restored cookie exists (restore needs no setting) but is capped')

k = d['browser_consent']
check(k['pending']['requests'] == 0 and not k['pending']['attribution_cookie'] and k['pending']['local_keys'] == [], 'consent required and undecided: no request, no cookie, no attribution/marker in localStorage (%s)' % k['pending'])
check(k['granted']['requests'] == 1 and k['granted']['attribution_cookie'], 'after consent is granted: cookie written and one re-issue request (%s)' % k['granted'])
print('%d failure(s)' % fail)
sys.exit(1 if fail else 0)
PY
echo "evidence: $OUT"
