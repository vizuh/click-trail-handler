"""E2E form-adapter scenarios (CF7, Fluent Forms, WPForms Lite). Usage: adapters.py <evidence-dir> [scenario ...]

Writes <evidence-dir>/adapters.json (observations only; verify_adapters.py judges them) and the optimizer-facing
landing HTML. Scenarios: landing, landing_server_only, returning, cache_leak, double_submit, consent, script_attrs.
Consent-off scenarios run first; consent and script_attrs switch Consent Mode on.
"""
import json, pathlib, re, sys, time, urllib.parse
from playwright.sync_api import sync_playwright
from adapters_lib import *

OUT = pathlib.Path(sys.argv[1])
OUT.mkdir(parents=True, exist_ok=True)
SELECTED = sys.argv[2:]
ATTRIBUTION_SETTINGS = {
    'enable_attribution': 1, 'require_consent': 0, 'cookie_days': 90, 'enable_js_injection': 1,
    'enable_link_decoration': 1, 'link_allowed_domains': 'example.test,calendly.com',
}


def set_js_injection(enabled):
    wp('option', 'update', 'clicutcl_attribution_settings', json.dumps(dict(ATTRIBUTION_SETTINGS, enable_js_injection=1 if enabled else 0)), '--format=json')


def attribution_cookie(ctx):
    raw = {c['name']: c['value'] for c in ctx.cookies()}.get('attribution')
    return json.loads(urllib.parse.unquote(raw)) if raw else {}


def records(provider, stored):
    """What the provider itself holds for the new submission(s), reduced to `ct_*` fields."""
    keep = lambda d: {k: v for k, v in d.items() if k.startswith('ct_') and (v != '' or k[3:] in CT_FIELDS)}
    out = []
    if provider == 'cf7':
        out = [{'entry': keep(r['fields']), 'meta': {}} for r in stored['provider'] if r['provider'] == 'cf7']
    elif provider.startswith('wpforms'):
        for r in stored['provider']:
            if r['provider'] != 'wpforms':
                continue
            fields = {'ct_' + k[len('Hidden '):]: v for k, v in r['fields'].items() if k.startswith('Hidden ')}
            out.append({'entry': {k: v for k, v in fields.items() if v != '' or k[3:] in CT_FIELDS}, 'meta': {'entry_meta_api': r['entry_meta_api'], 'entry_id': r['entry_id']}})
    else:
        for r in stored['fluent']:
            out.append({'entry': keep(r['response']), 'meta': {k: v for k, v in r['meta'].items() if k.startswith('ct_')}})
    return out


def observe(provider, page, ctx, snap, status, dom_before):
    stored = delta(snap)
    platform = PROVIDERS[provider]['platform']
    return {
        'status': status,
        'dom_before': dom_before,
        'cookie': {k: v for k, v in attribution_cookie(ctx).items() if k in CT_FIELDS or k in ('lt_campaign', 'ft_campaign')},
        'leads': [e['attribution'] for e in stored['events'] if e['platform'] == platform],
        'records': records(provider, stored),
        'dispatched': sum(1 for e in stored['capture'] if e.get('event_name') == 'form_submission'),
    }


def run_visit(browser, provider, urls, tag, settle_between=True):
    """Fresh context, visit `urls` in order, submit on the last page."""
    ctx = new_context(browser)
    page = ctx.new_page()
    snap = snapshot()
    for i, url in enumerate(urls):
        page.goto(BASE + PROVIDERS[provider]['path'] + url)
        if i < len(urls) - 1 and settle_between:
            page.wait_for_load_state('networkidle')
    dom = ct_inputs(page)
    status = submit(page, provider, tag)
    settle()
    result = observe(provider, page, ctx, snap, status, dom)
    ctx.close()
    return result


def landing(browser):
    return {p: run_visit(browser, p, ['?utm_source=t1&utm_medium=cpc&gclid=G1'], 'landing-' + p) for p in PROVIDERS}


def landing_server_only(browser):
    set_js_injection(False)
    try:
        return {p: run_visit(browser, p, ['?utm_source=t1&utm_medium=cpc&gclid=G1'], 'srv-' + p) for p in PROVIDERS}
    finally:
        set_js_injection(True)


def returning(browser):
    first, second = '?gclid=OLD&utm_source=old_src&utm_medium=cpc', '?fbclid=NEW&utm_source=new_src&utm_medium=social'
    return {p: run_visit(browser, p, [first, second], 'return-' + p) for p in PROVIDERS}


def cache_leak(browser):
    """Visitor A's server-rendered page served to visitor B, who submits with B's own cookie."""
    out = {}
    for provider in ('cf7', 'fluent', 'wpforms_text'):
        path = PROVIDERS[provider]['path']
        ctx_a = new_context(browser)
        page_a = ctx_a.new_page()
        page_a.goto(BASE + path + '?gclid=AAA&fbclid=AFB&utm_source=a_src&utm_medium=cpc')
        page_a.wait_for_load_state('networkidle')
        # A's second request carries A's cookie: the HTML a page cache would store.
        html_a = ctx_a.request.get(BASE + path).text()
        cookie_a = attribution_cookie(ctx_a)
        ctx_a.close()

        ctx_b = new_context(browser)
        page_b = ctx_b.new_page()
        page_b.goto(BASE + '/?fbclid=BFB&utm_source=b_src&utm_medium=social')
        page_b.wait_for_load_state('networkidle')
        cookie_b = attribution_cookie(ctx_b)

        def serve_cached(route, request=None, html=html_a):
            if route.request.resource_type == 'document':
                route.fulfill(status=200, content_type='text/html; charset=utf-8', body=html)
            else:
                route.continue_()

        ctx_b.route(BASE + path, serve_cached)
        snap = snapshot()
        page_b.goto(BASE + path)
        dom = ct_inputs(page_b)
        status = submit(page_b, provider, 'cache-' + provider)
        settle()
        result = observe(provider, page_b, ctx_b, snap, status, dom)
        result['cached_html_carries_A'] = 'AAA' in html_a
        result['cookie_A'] = {k: v for k, v in cookie_a.items() if k in CT_FIELDS}
        result['cookie_B_before'] = {k: v for k, v in cookie_b.items() if k in CT_FIELDS}
        out[provider] = result
        ctx_b.close()
    return out


def double_submit(browser):
    out = {}
    # (a) a real double click on the CF7 button.
    ctx = new_context(browser)
    page = ctx.new_page()
    page.goto(BASE + '/form-cf7/?utm_source=dbl&utm_medium=cpc&gclid=DBL')
    page.wait_for_load_state('networkidle')
    fill(page, 'cf7', 'double-click')
    snap = snapshot()
    page.dblclick(submit_button('cf7'))
    wait_done(page, 'cf7')
    settle(4)
    stored = delta(snap)
    out['double_click'] = {'leads': len([e for e in stored['events'] if e['platform'] == 'contact-form-7']), 'dispatched': sum(1 for e in stored['capture'] if e.get('event_name') == 'form_submission'), 'mail_sent': len(stored['provider'])}
    ctx.close()

    # (b) two concurrent REST posts of the same form payload (a retry or a replayed request).
    ctx = new_context(browser)
    page = ctx.new_page()
    page.goto(BASE + '/form-cf7/?utm_source=dbl2&utm_medium=cpc&gclid=DBL2')
    page.wait_for_load_state('networkidle')
    fill(page, 'cf7', 'parallel')
    snap = snapshot()
    statuses = page.evaluate(
        """async () => {
            const form = document.querySelector('form.wpcf7-form');
            const id = form.querySelector('input[name="_wpcf7"]').value;
            const url = '/wp-json/contact-form-7/v1/contact-forms/' + id + '/feedback';
            const post = () => fetch(url, { method: 'POST', body: new FormData(form) }).then((r) => r.json()).then((j) => j.status);
            return Promise.all([post(), post()]);
        }"""
    )
    settle(4)
    stored = delta(snap)
    out['parallel_rest'] = {'responses': statuses, 'leads': len([e for e in stored['events'] if e['platform'] == 'contact-form-7']), 'dispatched': sum(1 for e in stored['capture'] if e.get('event_name') == 'form_submission'), 'mail_sent': len(stored['provider'])}
    ctx.close()
    return out


def consent(browser):
    set_consent_mode(True)
    out = {}
    for provider in PROVIDERS:
        for decision in ('accept', 'deny', 'ignore'):
            ctx = new_context(browser)
            page = ctx.new_page()
            snap = snapshot()
            page.goto(BASE + PROVIDERS[provider]['path'] + '?utm_source=t5&utm_medium=cpc&gclid=G5')
            page.wait_for_selector('.ct-btn-primary', state='visible', timeout=10000)
            fill(page, provider, 'consent-%s-%s' % (decision, provider))
            t0 = time.monotonic()
            if decision == 'accept':
                page.click('.ct-btn-primary')
            elif decision == 'deny':
                page.click('.ct-btn-secondary')
            page.click(submit_button(provider))
            elapsed_ms = round((time.monotonic() - t0) * 1000)
            dom = ct_inputs(page)
            status = wait_done(page, provider)
            settle()
            result = observe(provider, page, ctx, snap, status, dom)
            result['decision_to_submit_ms'] = elapsed_ms
            result['consent_cookie'] = {c['name']: urllib.parse.unquote(c['value']) for c in ctx.cookies() if c['name'].startswith('ct_consent')}
            out['%s:%s' % (provider, decision)] = result
            ctx.close()
    return out


def script_attrs(browser):
    set_consent_mode(True)
    wp('option', 'update', 'clicutcl_gtm', json.dumps({'container_id': 'GTM-E2E1234', 'mode': 'standard'}), '--format=json')
    ctx = new_context(browser)
    html = ctx.request.get(BASE + '/landing/').text()
    ctx.close()
    (OUT / 'landing-optimizer.html').write_text(html)
    handles = ['clicutcl-consent-bridge-js', 'clicutcl-attribution-js', 'clicutcl-consent-js', 'clicutcl-events-js']
    tags = {}
    for handle in handles:
        # wp_get_script_tag() ids are `<handle>-js`, and these handles already end in `-js`.
        found = re.findall(r'<script\b[^>]*\bid=["\']%s-js["\'][^>]*>' % re.escape(handle), html)
        tags[handle] = found
    src_tags = re.findall(r'<script\b[^>]*\bsrc=["\'][^"\']*/assets/js/clicutcl-[^"\']*["\'][^>]*>', html)

    def count(tag, pattern):
        return len(re.findall(pattern, tag))

    attr_patterns = {
        'nowprocket': r'(?<![\w-])nowprocket(?![\w=-])',
        'data-no-optimize': r'(?<![\w-])data-no-optimize="1"',
        'data-no-defer': r'(?<![\w-])data-no-defer="1"',
        'data-noptimize': r'(?<![\w-])data-noptimize="1"',
        'data-cfasync': r'(?<![\w-])data-cfasync="false"',
    }
    consent_tag = re.findall(r'<script\b[^>]*\bid=["\']clicktrail-consent-mode["\'][^>]*>', html)
    return {
        'tag_counts': {h: len(t) for h, t in tags.items()},
        'src_tags_total': len(src_tags),
        'attribute_counts': {h: {a: count(t[0], p) for a, p in attr_patterns.items()} if t else None for h, t in tags.items()},
        'consent_snippet_tag': consent_tag[0] if consent_tag else None,
        'consent_snippet_nowprocket': bool(consent_tag) and count(consent_tag[0], attr_patterns['nowprocket']) == 1,
        'index_consent_default': html.find('id="clicktrail-consent-mode"'),
        'index_consent_gtag_default': html.find("gtag('consent', 'default'"),
        'index_gtm_bootstrap': html.find("'gtm.start'"),
        'sample_tag': tags['clicutcl-attribution-js'][0] if tags['clicutcl-attribution-js'] else None,
    }


SCENARIOS = {
    'landing': landing, 'landing_server_only': landing_server_only, 'returning': returning, 'cache_leak': cache_leak,
    'double_submit': double_submit, 'consent': consent, 'script_attrs': script_attrs,
}

if __name__ == '__main__':
    set_consent_mode(False)
    set_js_injection(True)
    wp('option', 'update', 'clicutcl_gtm', json.dumps({'container_id': '', 'mode': 'standard'}), '--format=json')
    result = {'scenarios': {}}
    with sync_playwright() as p:
        browser = p.chromium.launch()
        result['browser'] = 'Chromium ' + browser.version
        for name in (SELECTED or SCENARIOS):
            print('scenario', name, flush=True)
            result['scenarios'][name] = SCENARIOS[name](browser)
            (OUT / 'adapters.json').write_text(json.dumps(result, indent=1))
        browser.close()
    (OUT / 'adapters.json').write_text(json.dumps(result, indent=1))
    print('wrote', OUT / 'adapters.json')
