"""Replay a signed Calendly invitee.created webhook with the stamp the browser produced."""
import hashlib, hmac, json, sys, time, urllib.parse, urllib.request
book = json.load(open(sys.argv[1]))['book']
q = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(book).query))
body = json.dumps({'event': 'invitee.created', 'payload': {
    'uri': 'https://api.calendly.com/scheduled_events/E2E/invitees/I%d' % int(time.time()),
    'email': 'e2e@example.test',
    'tracking': {k: q.get(k) for k in ('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'salesforce_uuid')}}})
t = str(int(time.time()))
sig = hmac.new(b'e2e-secret', (t + '.' + body).encode(), hashlib.sha256).hexdigest()
req = urllib.request.Request('http://127.0.0.1:8088/wp-json/clicutcl/v2/webhooks/calendly', data=body.encode(),
    headers={'Content-Type': 'application/json', 'Calendly-Webhook-Signature': 't=%s,v1=%s' % (t, sig)})
print('stamp', q.get('salesforce_uuid'))
print('response', urllib.request.urlopen(req).read().decode())
