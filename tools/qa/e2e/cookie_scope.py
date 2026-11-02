"""E2E (#154): land on www.example.test with a click ID, then open and submit CF7 on example.test; prints JSON evidence."""
import json, sys
from playwright.sync_api import sync_playwright

PORT = 8088
WWW = f'http://www.example.test:{PORT}/landing/'
APEX = f'http://example.test:{PORT}/landing/'
out = {'mode': sys.argv[1] if len(sys.argv) > 1 else ''}
with sync_playwright() as p:
    browser = p.chromium.launch(args=['--host-resolver-rules=MAP www.example.test 127.0.0.1, MAP example.test 127.0.0.1'])
    page = browser.new_page()
    page.add_init_script("Object.defineProperty(navigator, 'webdriver', { get: () => false })")
    page.goto(WWW + '?gclid=GA&utm_source=google&utm_medium=cpc&utm_campaign=g1')
    page.wait_for_load_state('networkidle')
    cookies = [c for c in page.context.cookies() if c['name'] == 'attribution']
    out['landing_cookie_domain'] = cookies[0]['domain'] if cookies else None
    page.goto(APEX)
    page.wait_for_load_state('networkidle')
    out['apex_cookie_present'] = bool([c for c in page.context.cookies(APEX) if c['name'] == 'attribution'])
    out['apex_hidden_gclid'] = page.evaluate("() => (document.querySelector('input[name=\"ct_gclid\"]') || {}).value ?? null")
    page.fill('input[name="your-name"]', 'E2E Visitor')
    page.fill('input[name="your-email"]', 'e2e@example.test')
    if page.locator('input[name="your-subject"]').count():
        page.fill('input[name="your-subject"]', 'E2E')
    page.fill('textarea[name="your-message"]', 'cookie scope www to apex')
    page.click('input[type="submit"]')
    page.wait_for_selector('form.sent, form.failed, form.invalid, form.spam, form.aborted', timeout=20000)
    out['cf7_status'] = page.get_attribute('form.wpcf7-form', 'class')
    browser.close()
print(json.dumps(out, indent=1))
