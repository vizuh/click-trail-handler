"""E2E: Google A -> Meta B -> decorated links -> CF7 submit; prints JSON evidence."""
import json, sys, urllib.parse
from playwright.sync_api import sync_playwright

BASE = 'http://127.0.0.1:8088/landing/'
out = {}
with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page()
    # ClickTrail skips bots by design (navigator.webdriver); present as a normal browser.
    page.add_init_script("Object.defineProperty(navigator, 'webdriver', { get: () => false })")
    page.goto(BASE + '?gclid=GA&utm_source=google&utm_medium=cpc&utm_campaign=g1')
    page.goto(BASE + '?fbclid=FB&utm_source=facebook&utm_medium=paid&utm_campaign=m1')
    page.wait_for_load_state('networkidle')
    cookies = {c['name']: c['value'] for c in page.context.cookies()}
    out['cookie'] = json.loads(urllib.parse.unquote(cookies.get('attribution', '%7B%7D')))
    out['legacy_cookie_present'] = 'ct_attribution' in cookies
    ls = page.evaluate("() => JSON.parse(localStorage.getItem('attribution') || '{}')")
    out['history'] = json.loads(ls.get('data', {}).get('click_id_history', '[]'))
    for anchor in ('apply', 'book'):
        page.dispatch_event('#' + anchor, 'mousedown')
        out[anchor] = page.get_attribute('#' + anchor, 'href')
    out['hidden'] = page.evaluate("""() => Object.fromEntries(['ct_gclid','ct_fbclid','ct_lt_campaign','ct_attribution_selected_click_id']
        .map((n) => [n, (document.querySelector(`input[name="${n}"]`) || {}).value ?? null]))""")
    page.fill('input[name="your-name"]', 'E2E Visitor')
    page.fill('input[name="your-email"]', 'e2e@example.test')
    if page.locator('input[name="your-subject"]').count():
        page.fill('input[name="your-subject"]', 'E2E')
    page.fill('textarea[name="your-message"]', 'attribution round trip')
    page.click('input[type="submit"]')
    page.wait_for_selector('form.sent, form.failed, form.invalid, form.spam, form.aborted', timeout=20000)
    out['cf7_status'] = page.get_attribute('form.wpcf7-form', 'class')
    browser.close()
print(json.dumps(out, indent=1))
