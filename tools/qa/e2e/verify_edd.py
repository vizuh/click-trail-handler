"""Judge edd.json. Usage: verify_edd.py <evidence-dir>

Prints PASS / FAIL per check and writes edd-results.json. Any FAIL exits 1.
"""
import json, pathlib, re, sys

out = pathlib.Path(sys.argv[1])
data = json.loads((out / 'edd.json').read_text())['scenarios']
results = []


def check(ok, scenario, message):
    results.append({'status': 'PASS' if ok else 'FAIL', 'scenario': scenario, 'message': message})
    print('%-5s %s: %s' % ('PASS' if ok else 'FAIL', scenario, message))


def purchased(name, res, gclid, campaign):
    scenario = name
    orders = res['orders']
    check(len(orders) == 1, scenario, 'exactly one new order (%d)' % len(orders))
    if len(orders) != 1:
        return
    order = orders[0]
    meta = order['meta']
    oid = order['id']
    check(order['status'] == 'complete' and order['type'] == 'sale', scenario, 'order %d is a complete sale (%s/%s)' % (oid, order['status'], order['type']))
    check(meta.get('_clicutcl_gclid') == gclid and meta.get('_clicutcl_lt_gclid') == gclid, scenario, 'order meta carries gclid %s (%s)' % (gclid, meta.get('_clicutcl_gclid')))
    check(meta.get('_clicutcl_lt_source') == 'google' and meta.get('_clicutcl_ft_medium') == 'cpc', scenario, 'order meta carries first/last touch source and medium')
    if campaign:
        check(meta.get('_clicutcl_ft_campaign') == campaign and meta.get('_clicutcl_lt_campaign') == campaign, scenario, 'order meta carries campaign %s' % campaign)
    check(bool(meta.get('_clicutcl_visitor_id')) and bool(meta.get('_clicutcl_session_id')), scenario, 'order meta carries visitor and session IDs')
    snapshot = json.loads(meta.get('_clicutcl_consent') or '{}')
    check(snapshot.get('marketing') is True, scenario, 'order meta carries the checkout consent snapshot (marketing=true)')
    check(meta.get('_clicutcl_tracking_sent') == 'yes', scenario, 'the one-shot marker is set after the receipt')
    event_id = 'purchase_edd_%d' % oid
    pushes = res['pushes']
    check(len(pushes) == 1, scenario, 'exactly one dataLayer purchase on the receipt (%d)' % len(pushes))
    if pushes:
        push = pushes[0]
        ecom = push.get('ecommerce', {})
        check(push.get('event_id') == event_id, scenario, 'dataLayer event_id is %s (%s)' % (event_id, push.get('event_id')))
        check(ecom.get('transaction_id') == order['number'] and ecom.get('value') == order['total'] and ecom.get('currency') == order['currency'], scenario, 'dataLayer transaction_id/value/currency match the order (%s %s %s)' % (ecom.get('transaction_id'), ecom.get('value'), ecom.get('currency')))
        items = ecom.get('items', [])
        check(len(items) == 1 and items[0].get('item_id') > 0 and items[0].get('quantity') == 1 and items[0].get('item_name') == 'E2E Download', scenario, 'dataLayer carries the purchased item')
        check(push.get('gclid') == gclid and push.get('lt_source') == 'google' and push.get('ft_source') == 'google', scenario, 'dataLayer carries the stored attribution (gclid=%s lt_source=%s)' % (push.get('gclid'), push.get('lt_source')))
    check(all(r['purchase_pushes'] == 0 and not r['event_ids_in_html'] for r in res['reloads']) and len(res['reloads']) == 2, scenario, 'two receipt reloads emit no second purchase')
    dispatched = res['dispatched']
    check(len(dispatched) == 1, scenario, 'exactly one server-side purchase dispatched, none added by the reloads (%d)' % len(dispatched))
    if dispatched:
        event = dispatched[0]
        check(event.get('event_id') == event_id and event['meta'].get('order_id') == oid, scenario, 'dispatched event_id %s and order_id %s' % (event.get('event_id'), oid))
        check(event['commerce'].get('transaction_id') == order['number'] and event['commerce'].get('value') == order['total'], scenario, 'dispatched commerce matches the order')
        last = event.get('attribution', {}).get('last_touch', {})
        first = event.get('attribution', {}).get('first_touch', {})
        check(last.get('gclid') == gclid and last.get('source') == 'google' and first.get('source') == 'google', scenario, 'dispatched event carries first/last touch from the order')
        check(event.get('consent', {}).get('marketing') is True and event['meta'].get('platform') == 'edd', scenario, 'dispatched event carries the order consent snapshot and platform edd')


def blocked(name, res, label):
    scenario = name
    orders = res['orders']
    check(len(orders) == 1, scenario, 'exactly one new order (%d)' % len(orders))
    if len(orders) != 1:
        return
    meta = orders[0]['meta']
    check(sorted(meta) == ['_clicutcl_consent'], scenario, '%s: only the consent snapshot is stored, no attribution (keys: %s)' % (label, sorted(meta)))
    snapshot = json.loads(meta.get('_clicutcl_consent') or '{}')
    check(snapshot.get('marketing') is False, scenario, 'the stored snapshot records marketing=false')
    check(res['pushes'] == [] and not res['receipt_has_event_id'], scenario, 'no purchase is pushed on the receipt')
    check(all(r['purchase_pushes'] == 0 for r in res['reloads']), scenario, 'reloads push nothing')
    check(res['dispatched'] == [], scenario, 'no purchase is dispatched server-side')


if 'landing' in data:
    purchased('S1 landing (consent not required)', data['landing'], 'EDDGCLID', 'edd-launch')
    check(data['landing']['edd'] == '3.7.3', 'S1 landing (consent not required)', 'ran against EDD %s' % data['landing']['edd'])
if 'consent_denied' in data:
    blocked('S2 consent denied', data['consent_denied'], 'banner Deny')
    check(not data['consent_denied']['attribution_cookie'], 'S2 consent denied', 'the browser stored no attribution cookie')
if 'consent_accepted' in data:
    purchased('S3 consent accepted', data['consent_accepted'], 'ACCEPTEDGCLID', 'accepted')
if 'consent_withdrawn' in data:
    blocked('S4 consent withdrawn after landing', data['consent_withdrawn'], 'consent cookie flipped to denied')
    check(bool(data['consent_withdrawn']['attribution_cookie']), 'S4 consent withdrawn after landing', 'setup: the stale attribution cookie was still sent with the checkout')

if 'privacy' in data:
    p, s = data['privacy'], 'S5 privacy lifecycle (real EDD order queries)'
    check(p['ids']['landing'] > 0 and p['ids']['accepted'] > 0, s, 'setup: both orders found by billing email')
    check(p['export_items'] > 0 and p['export_has_gclid'] and p['export_done'], s, 'WordPress exporter returns the order meta (%d items, gclid included)' % p['export_items'])
    check(len(p['landing_keys_before']) > 0 and p['landing_keys_after_fresh'] == p['landing_keys_before'] and p['retention_fresh']['removed'] == 0, s, 'retention keeps an order inside the window')
    check(p['retention_aged']['removed'] == 1 and p['landing_keys_after_aged'] == [] and p['retention_aged']['failed'] == 0, s, 'retention removes an order aged 200 days (%s)' % p['retention_aged'])
    check(p['landing_foreign_after'] == p['landing_foreign_before'] and p['accepted_foreign_after'] and all(k.startswith('_edd_') for k in p['accepted_foreign_after']), s, 'EDD-owned order meta is left untouched')
    check(len(p['accepted_keys_before']) > 0 and p['erase']['items_removed'] and not p['erase']['items_retained'] and p['accepted_keys_after'] == [], s, 'WordPress eraser removes the order meta')
    check(p['purge_all']['removed'] >= 2 and not p['purge_all']['remaining'] and p['keys_after_purge'] == [[], []], s, 'manual purge clears every order (%s)' % p['purge_all'])

    check(p['landing_sent_after_aged'] == 'yes' and p['accepted_sent_after_erase'] == 'yes' and p['sent_after_purge'] == ['yes', 'yes'], s, 'the purchase-sent marker survives retention, erase and the manual purge (a later receipt visit cannot re-push)')

log = out / 'debug.log'
errors = []
if log.exists():
    for line in log.read_text().splitlines():
        if re.search(r'PHP (Fatal|Warning|Notice|Deprecated|Parse)|WordPress database error', line) and re.search(r'clicutcl|click-trail-handler|Edd_Order_Privacy', line, re.I):
            errors.append(line[:300])
check(not errors, 'debug.log', 'no PHP error or database error raised by ClickTrail (%d)' % len(errors))
for line in errors[:5]:
    print('      ', line)

(out / 'edd-results.json').write_text(json.dumps(results, indent=1))
failed = [r for r in results if r['status'] == 'FAIL']
print('%d checks, %d failed' % (len(results), len(failed)))
sys.exit(1 if failed else 0)
