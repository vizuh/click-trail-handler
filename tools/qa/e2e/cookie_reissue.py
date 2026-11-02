"""E2E (#141): attribution cookie server re-issue on the wire; prints JSON evidence that cookie-reissue.sh judges.

Raw HTTP (no browser) for page responses and the route's gates, then Chromium for the real round trip: the landing writes
the cookie, the page asks `POST /clicutcl/v2/cookie-refresh`, the response sets it again. Serves one WordPress as
www.example.test / example.test (mu/ct-e2e-dynamic-host.php) so the cookie `Domain` can be compared with the script's.
"""
import http.client, json, re, subprocess, sys, time, urllib.parse
from pathlib import Path
from playwright.sync_api import sync_playwright

HERE = Path(__file__).resolve().parent
PORT = 8088
WWW = 'www.example.test'
APEX = 'example.test'
ROUTE = '/wp-json/clicutcl/v2/cookie-refresh'
LANDING = '/landing/?gclid=X&utm_source=t'
VALUE = json.dumps({'ft_source': 'google', 'ft_medium': 'cpc', 'gclid': 'G 1', 'lt_campaign': 'Bras\u00edlia 100%', 'session_number': 3}, separators=(',', ':'), ensure_ascii=False)
ENCODED = urllib.parse.quote(VALUE, safe="-_.!~*'()")  # encodeURIComponent
GRANTED = urllib.parse.quote('{"marketing":true,"analytics":true}')
DENIED = urllib.parse.quote('{"marketing":false,"analytics":false}')
SETTINGS = {'enable_attribution': 1, 'require_consent': 0, 'cookie_days': 90, 'enable_js_injection': 1, 'cookie_scope': 'registrable', 'attribution_cookie_reissue': 1}


def wp(*args):
    proc = subprocess.run([str(HERE / 'wp.sh'), *args], capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError('wp %s failed: %s' % (' '.join(args), proc.stderr[-600:]))
    return proc.stdout


def server_log():
    proc = subprocess.run(['podman', 'exec', 'ct-e2e-wp', 'cat', '/var/www/html/wp-content/ct-set-cookie.jsonl'], capture_output=True, text=True)
    return [json.loads(line) for line in proc.stdout.splitlines() if line.strip()] if proc.returncode == 0 else []


def settings(**overrides):
    wp('option', 'update', 'clicutcl_attribution_settings', json.dumps(dict(SETTINGS, **overrides)), '--format=json')


def consent_mode(enabled):
    mode = {'enabled': bool(enabled), 'mode': 'strict', 'regions': ['EEA', 'UK'], 'cmp_source': 'plugin', 'cmp_timeout_ms': 3000,
            'cookie_name': 'ct_consent', 'gcm_analytics_key': 'analytics_storage'}
    wp('option', 'update', 'clicutcl_consent_mode', json.dumps(mode), '--format=json')


def raw(method, path, host=WWW, cookie='', body=None, token=None):
    conn = http.client.HTTPConnection('127.0.0.1', PORT, timeout=30)
    headers = {'Host': '%s:%d' % (host, PORT)}
    if cookie:
        headers['Cookie'] = cookie
    if body is not None:
        headers['Content-Type'] = 'application/json'
    if token:
        headers['X-Clicutcl-Token'] = token
    conn.request(method, path, body=body, headers=headers)
    resp = conn.getresponse()
    data = resp.read().decode('utf-8', 'replace')
    out = {'status': resp.status, 'set_cookie': [v for k, v in resp.getheaders() if k.lower() == 'set-cookie'],
           'cache_control': [v for k, v in resp.getheaders() if k.lower() == 'cache-control'], 'body': data if method != 'HEAD' else ''}
    conn.close()
    return out


def page_token(host=WWW):
    html = raw('GET', '/landing/', host=host)['body']
    match = re.search(r'"eventsToken":"([^"]+)"', html)
    return match.group(1) if match else ''


def post(token, cookie='', host=WWW, with_token=True, **extra):
    # The script reports the domain it wrote the cookie with; `registrable` scope on this site means example.test.
    body = dict({'token': token, 'domain_applied': APEX}, **extra)
    return raw('POST', ROUTE, host=host, cookie=cookie, body=json.dumps(body), token=token if with_token else None)


def decode(result):
    try:
        return json.loads(result['body'])
    except ValueError:
        return None


out = {}
settings()
consent_mode(False)
token = page_token()
out['token_present'] = bool(token)

# 1. Page responses never carry Set-Cookie, with or without a valid attribution cookie and consent cookie.
pages = {}
for name, cookie in (('anonymous', ''), ('attribution_cookie', 'attribution=' + ENCODED), ('attribution_and_consent', 'attribution=%s; ct_consent=%s' % (ENCODED, GRANTED))):
    pages[name] = [raw(m, LANDING, cookie=cookie) for m in ('GET', 'GET', 'HEAD')] + [raw('GET', '/?gclid=X', cookie=cookie)]
out['pages'] = {k: [{'status': r['status'], 'set_cookie': r['set_cookie']} for r in v] for k, v in pages.items()}

# 2. The route on the wire (consent not required).
routes = {}
routes['valid'] = post(token, 'attribution=' + ENCODED)
routes['valid_apex'] = post(token, 'attribution=' + ENCODED, host=APEX)
routes['host_only_claim'] = post(token, 'attribution=' + ENCODED, domain_applied='')
routes['foreign_domain_claim'] = post(token, 'attribution=' + ENCODED, domain_applied='other.test')
routes['no_domain_claim'] = post(token, 'attribution=' + ENCODED, domain_applied=None)
routes['expires_in'] = post(token, 'attribution=' + ENCODED, expires_in=3600)
routes['no_cookie'] = post(token)
routes['legacy_name_only'] = post(token, 'ct_attribution=' + ENCODED)
routes['invalid_json'] = post(token, 'attribution=' + urllib.parse.quote('not json'))
routes['nested'] = post(token, 'attribution=' + urllib.parse.quote('{"a":{"b":"c"}}'))
routes['oversized'] = post(token, 'attribution=' + urllib.parse.quote(json.dumps({('k%d' % i): 'x' * 250 for i in range(20)})))
routes['no_token'] = post('', 'attribution=' + ENCODED, with_token=False)
routes['bad_token'] = post('garbage', 'attribution=' + ENCODED)
routes['get_not_allowed'] = raw('GET', ROUTE, cookie='attribution=' + ENCODED)
out['routes'] = {k: {'status': v['status'], 'set_cookie': v['set_cookie'], 'cache_control': v['cache_control'], 'json': decode(v)} for k, v in routes.items()}

# 3. Consent required: the request's own consent cookie decides.
settings(require_consent=1)
consent_mode(True)
token_c = page_token()
consent = {}
consent['none'] = post(token_c, 'attribution=' + ENCODED)
consent['denied'] = post(token_c, 'attribution=%s; ct_consent=%s' % (ENCODED, DENIED))
consent['granted'] = post(token_c, 'attribution=%s; ct_consent=%s' % (ENCODED, GRANTED))
out['consent_routes'] = {k: {'status': v['status'], 'set_cookie': v['set_cookie'], 'json': decode(v)} for k, v in consent.items()}
consent_mode(False)
settings()

# 4. Setting off: the route is closed and the script is not given a URL.
settings(attribution_cookie_reissue=0)
off_html = raw('GET', '/landing/')['body']
off = post(token, 'attribution=' + ENCODED)
out['setting_off'] = {'status': off['status'], 'set_cookie': off['set_cookie'], 'code': (decode(off) or {}).get('code'), 'config_url': re.search(r'"cookieReissueUrl":"([^"]*)"', off_html).group(1) if re.search(r'"cookieReissueUrl":"([^"]*)"', off_html) else None}
settings()


# What Safari does to script-written cookies (W1/W5 in specs/009 research.md), emulated: a `document.cookie` write of the
# attribution cookie expires in 24 hours. An HTTP-set cookie is not touched, so a jar expiry of 90 days proves the
# browser applied the server's Set-Cookie over the script's.
ITP_CAP = """
(() => {
  const d = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie');
  Object.defineProperty(document, 'cookie', { configurable: true,
    get() { return d.get.call(document); },
    set(v) { if (/^attribution=/.test(v) && !/max-age=0/i.test(v)) v = v.replace(/;\\s*expires=[^;]*/i, '; max-age=86400'); d.set.call(document, v); } });
})();
"""
HOST_RULES = ['--host-resolver-rules=MAP www.example.test 127.0.0.1, MAP example.test 127.0.0.1']


def settle(page):
    page.wait_for_load_state('load')
    time.sleep(2)  # the keepalive POST is fire-and-forget; networkidle never settles while it is open


def browser_run(playwright, scope, reissue=True):
    """Landing with a click ID, a second plain page, then the cookie dropped and restored from the mirror."""
    settings(cookie_scope=scope, attribution_cookie_reissue=1 if reissue else 0)
    browser = playwright.chromium.launch(args=HOST_RULES)
    ctx = browser.new_context()
    ctx.add_init_script("Object.defineProperty(navigator, 'webdriver', { get: () => false })")
    ctx.add_init_script(ITP_CAP)
    page = ctx.new_page()

    def attribution_cookies():
        return [{k: c[k] for k in ('domain', 'path', 'expires', 'httpOnly', 'secure', 'sameSite')} for c in ctx.cookies() if c['name'] == 'attribution']

    def phase(url):
        before = len(server_log())
        started = time.time()
        nav = page.goto(url)
        settle(page)
        return {'document_set_cookie': [h['value'] for h in nav.headers_array() if h['name'].lower() == 'set-cookie'],
                'refreshes': server_log()[before:], 'cookies': attribution_cookies(), 'expected_expires': started + 90 * 86400,
                'capped_expires': started + 86400, 'marker': page.evaluate("() => localStorage.getItem('ct_cookie_reissue')"),
                'mirror': page.evaluate("() => !!localStorage.getItem('attribution')")}

    result = {'scope': scope, 'reissue': reissue}
    result['landing'] = phase('http://%s:%d/landing/?gclid=GA&utm_source=google&utm_medium=cpc&utm_campaign=g1' % (WWW, PORT))
    result['second'] = phase('http://%s:%d/landing/' % (WWW, PORT))
    # The cookie is gone (ITP) but the mirror survives: drop the cookie only, keep localStorage.
    ctx.clear_cookies(name='attribution')
    result['after_clear_cookies'] = attribution_cookies()
    result['restore'] = phase('http://%s:%d/landing/' % (WWW, PORT))
    browser.close()
    return result


def consent_browser(playwright):
    """Consent required and undecided: nothing is written and nothing is requested; then consent is granted."""
    settings(require_consent=1, cookie_scope='host')
    consent_mode(True)
    browser = playwright.chromium.launch(args=HOST_RULES)
    ctx = browser.new_context()
    ctx.add_init_script("Object.defineProperty(navigator, 'webdriver', { get: () => false })")
    page = ctx.new_page()
    refreshes = []
    page.on('request', lambda req: refreshes.append(req.url) if req.url.endswith('/clicutcl/v2/cookie-refresh') else None)
    result = {}
    page.goto('http://%s:%d/landing/?gclid=GA&utm_source=google' % (WWW, PORT))
    settle(page)
    result['pending'] = {'requests': len(refreshes), 'attribution_cookie': any(c['name'] == 'attribution' for c in ctx.cookies()),
                         'local_keys': page.evaluate("() => Object.keys(localStorage).filter((k) => /^(attribution|ct_cookie_reissue)$/.test(k))")}
    ctx.add_cookies([{'name': 'ct_consent', 'value': GRANTED, 'domain': WWW, 'path': '/'}])
    page.goto('http://%s:%d/landing/?gclid=GB&utm_source=google' % (WWW, PORT))
    settle(page)
    result['granted'] = {'requests': len(refreshes), 'attribution_cookie': any(c['name'] == 'attribution' for c in ctx.cookies())}
    browser.close()
    consent_mode(False)
    return result


with sync_playwright() as p:
    out['browser_registrable'] = browser_run(p, 'registrable')
    out['browser_host'] = browser_run(p, 'host')
    # Control: the same landing with the server re-issue off and the ITP cap emulated: the cookie stays capped at 24 hours.
    out['browser_reissue_off'] = browser_run(p, 'registrable', reissue=False)
    settings()
    out['browser_consent'] = consent_browser(p)
settings()
out['expected_value_encoded'] = ENCODED
print(json.dumps(out, indent=1))
