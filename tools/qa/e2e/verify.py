"""Assert the E2E evidence collected by ci.sh. Usage: verify.py <evidence-dir>; exits 1 on any mismatch."""
import json, pathlib, sys, urllib.parse

out = pathlib.Path(sys.argv[1])
failures = []


def check(ok, message):
    print(('ok    ' if ok else 'FAIL  ') + message)
    if not ok:
        failures.append(message)


journey = json.loads((out / 'journey.json').read_text())
dual = json.loads((out / 'dual.json').read_text())
leads = [json.loads(line)['attribution'] for line in (out / 'leads.jsonl').read_text().splitlines() if line.strip()]
sent = [json.loads(line) for line in (out / 'capture.jsonl').read_text().splitlines() if line.strip()]
webhook = (out / 'webhook.txt').read_text()

# Browser: Google A, then Meta B.
cookie = journey['cookie']
check(cookie.get('attribution_selected_click_id') == 'FB', 'cookie selects the newest click (FB)')
check('click_id_history' not in cookie, 'history stays out of the cookie')
check([h['k'] for h in journey['history']] == ['gclid', 'fbclid'], 'browser history records gclid then fbclid')
apply = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(journey['apply']).query))
check(apply.get('ref') == 'site' and apply.get('utm_campaign') == 'm1' and apply.get('fbclid') == 'FB',
      'decorated link keeps the site param and carries the Meta touch')
book = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(journey['book']).query))
check(book.get('salesforce_uuid') == 'ct1;fbclid=FB', 'Calendly stamp carries the selected click')
check(journey['hidden'].get('ct_attribution_selected_click_id') == 'FB', 'CF7 hidden field carries the selection')
check('sent' in (journey.get('cf7_status') or ''), 'CF7 reports the submission as sent')

# Stored and dispatched lead (first form submission).
lead = leads[0] if leads else {}
check(lead.get('lt_campaign') == 'm1' and lead.get('ft_campaign') == 'g1', 'stored lead: last touch m1, first touch g1')
check(lead.get('fbclid') == 'FB' and lead.get('attribution_selected_click_id') == 'FB', 'stored lead: fbclid and selection FB')
check('click_id_history' not in lead, 'stored lead carries no history')
dispatched_leads = [e for e in sent if e.get('event_name') == 'form_submission']
check(bool(dispatched_leads) and dispatched_leads[0]['attribution'].get('attribution_selected_click_id') == 'FB',
      'dispatched lead carries selection FB')

# Calendly booking replayed with the browser's stamp.
check('"success":true' in webhook, 'webhook accepted')
bookings = [e for e in sent if e.get('event_name') == 'book_appointment']
booking = bookings[-1]['attribution'] if bookings else {}
check(booking.get('fbclid') == 'FB' and not booking.get('gclid'), 'dispatched booking: fbclid FB, no gclid')

# Both cookies, different click IDs: canonical wins everywhere.
check(dual.get('browser_gclid') == 'CANON' and dual.get('hidden_ct_gclid') == 'CANON', 'browser and rendered field read the canonical cookie')
check(len(leads) > 1 and leads[1].get('gclid') == 'CANON', 'stored dual-cookie lead uses the canonical cookie')

print(f'{len(failures)} failure(s)')
sys.exit(1 if failures else 0)
