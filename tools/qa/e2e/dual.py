"""Both cookies with different click IDs, no new touch, then a CF7 submit; prints what the browser resolves."""
import json, urllib.parse
from playwright.sync_api import sync_playwright
enc = lambda d: urllib.parse.quote(json.dumps(d))
with sync_playwright() as p:
    b = p.chromium.launch(); ctx = b.new_context(); page = ctx.new_page()
    page.add_init_script("Object.defineProperty(navigator, 'webdriver', { get: () => false })")
    ctx.add_cookies([
        {'name': 'ct_attribution', 'value': enc({'ft_gclid': 'LEGACY', 'lt_gclid': 'LEGACY', 'gclid': 'LEGACY', 'lt_campaign': 'legacy'}), 'url': 'http://127.0.0.1:8088/'},
        {'name': 'attribution', 'value': enc({'ft_gclid': 'CANON', 'lt_gclid': 'CANON', 'gclid': 'CANON', 'lt_campaign': 'canonical', 'ft_campaign': 'canonical'}), 'url': 'http://127.0.0.1:8088/'},
    ])
    page.goto('http://127.0.0.1:8088/landing/'); page.wait_for_load_state('networkidle')
    out = {'browser_gclid': page.evaluate("() => window.ClickTrail.getData().gclid"),
           'hidden_ct_gclid': page.evaluate("() => (document.querySelector('input[name=ct_gclid]') || {}).value || null")}
    page.fill('input[name="your-name"]', 'Dual'); page.fill('input[name="your-email"]', 'dual@example.test')
    if page.locator('input[name="your-subject"]').count(): page.fill('input[name="your-subject"]', 'Dual')
    page.fill('textarea[name="your-message"]', 'dual cookie'); page.click('input[type="submit"]')
    page.wait_for_selector('form.sent, form.failed, form.invalid, form.spam', timeout=20000)
    out['cookies_after'] = sorted(c['name'] for c in ctx.cookies() if 'attribution' in c['name'])
    print(json.dumps(out)); b.close()
