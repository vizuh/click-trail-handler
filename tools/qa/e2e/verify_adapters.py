"""Judge adapters.json. Usage: verify_adapters.py <evidence-dir>

Prints PASS / FAIL / FINDING per check and writes results.json. FAIL (exit 1) means the 1.11.2 behaviour the
specs claim is not there. FINDING (exit 0) is a recorded defect that is known and tracked (#152 cache leak,
#140 double submit) or a documented limitation; it flips to PASS the day the behaviour is fixed.
"""
import json, pathlib, re, sys

out = pathlib.Path(sys.argv[1])
data = json.loads((out / 'adapters.json').read_text())['scenarios']
results = []


def record(status, scenario, subject, message):
    results.append({'status': status, 'scenario': scenario, 'subject': subject, 'message': message})
    print('%-8s%s %s: %s' % (status, scenario, subject, message))


def check(ok, scenario, subject, message):
    record('PASS' if ok else 'FAIL', scenario, subject, message)


def one_record(res):
    recs = res.get('records') or []
    return recs[0] if len(recs) == 1 else None


def touch_checks(scenario, name, res, expect):
    """`expect` maps a ClickTrail key to the value that must be in the lead, the provider entry and (Fluent) its meta."""
    check(res['status'] == 'sent', scenario, name, 'provider reports the submission as sent (%s)' % res['status'])
    leads = res['leads']
    check(len(leads) == 1, scenario, name, 'exactly one ClickTrail form_submission row (%d)' % len(leads))
    lead = leads[0] if leads else {}
    check(all(lead.get(k) == v for k, v in expect.items()), scenario, name, 'stored lead carries %s' % expect)
    rec = one_record(res)
    check(rec is not None, scenario, name, 'exactly one provider record (%d)' % len(res.get('records') or []))
    if rec is None:
        return
    entry = rec['entry']
    check(all(entry.get('ct_' + k) == v for k, v in expect.items()), scenario, name, 'provider entry carries ct_ fields %s (got %s)' % (expect, {k: entry.get('ct_' + k) for k in expect}))
    if name == 'fluent':
        meta = rec['meta']
        check(all(meta.get('ct_' + k) == v for k, v in expect.items()), scenario, name, 'Fluent submission meta carries %s' % expect)
    if name.startswith('wpforms'):
        record('FINDING' if not rec['meta'].get('entry_meta_api') else 'PASS', scenario, name,
               'WPForms Lite has no entry/entry-meta API (wpforms()->entry_meta unset, entry_id=%s): entry meta cannot be asserted; the processed field values captured at wpforms_process_complete were asserted instead' % rec['meta'].get('entry_id'))


for scenario, expect_dom in (('landing', True), ('landing_server_only', False)):
    for name, res in data.get(scenario, {}).items():
        touch_checks('S1 ' + scenario, name, res, {'gclid': 'G1', 'lt_source': 't1', 'lt_medium': 'cpc', 'attribution_selected_click_id': 'G1'})
        if scenario == 'landing_server_only' and name in ('cf7', 'fluent'):
            pre = res['dom_before'].get('ct_gclid', '')
            record('PASS' if pre == '' else 'FINDING', 'S1 landing_server_only', name, 'with browser injection off the rendered field was %r at submit, so the stored value is the server submit-time refresh' % pre)

for name, res in data.get('returning', {}).items():
    touch_checks('S2 returning', name, res, {'fbclid': 'NEW', 'lt_source': 'new_src', 'ft_source': 'old_src', 'attribution_selected_click_id': 'NEW'})
    lead = res['leads'][0] if res['leads'] else {}
    record('INFO', 'S2 returning', name, 'lead gclid=%r fbclid=%r lt_medium=%r ft_medium=%r' % (lead.get('gclid'), lead.get('fbclid'), lead.get('lt_medium'), lead.get('ft_medium')))

for name, res in data.get('cache_leak', {}).items():
    check(res['status'] == 'sent', 'S3 cache_leak', name, 'submit succeeded (%s)' % res['status'])
    if name.startswith('wpforms'):
        record('INFO', 'S3 cache_leak', name, "Lite fills no field at render (the hidden type is a Pro field, text fields are not filled), so the cached HTML carries A's gclid: %s" % res['cached_html_carries_A'])
    else:
        check(res['cached_html_carries_A'], 'S3 cache_leak', name, "setup: the cached HTML snapshot carries visitor A's gclid")
    check(res['cookie_B_before'].get('gclid') in (None, '') and res['cookie_B_before'].get('fbclid') == 'BFB', 'S3 cache_leak', name, "setup: visitor B's cookie lacks gclid and holds fbclid=BFB (%s)" % res['cookie_B_before'])
    rec = one_record(res)
    lead = res['leads'][0] if res['leads'] else {}
    entry = rec['entry'] if rec else {}
    check(entry.get('ct_fbclid') == 'BFB', 'S3 cache_leak', name, "provider entry holds B's own fbclid (%r)" % entry.get('ct_fbclid'))
    entry_leak = {k: v for k, v in entry.items() if 'AAA' in str(v) or 'a_src' in str(v) or 'AFB' in str(v)}
    lead_leak = {k: v for k, v in lead.items() if 'AAA' in str(v) or 'a_src' in str(v) or 'AFB' in str(v)}
    record('FINDING' if entry_leak else 'PASS', 'S3 cache_leak', name, "provider entry %s A's values %s" % ('LEAKS' if entry_leak else 'holds none of', entry_leak or ''))
    record('FINDING' if lead_leak else 'PASS', 'S3 cache_leak', name, "ClickTrail stored lead %s A's values %s" % ('LEAKS' if lead_leak else 'holds none of', lead_leak or ''))

dbl = data.get('double_submit', {})
for variant, res in dbl.items():
    n = res['leads']
    record('FINDING' if n > 1 else 'PASS', 'S6 double_submit', variant, '%d form_submission row(s), %d dispatched, %d CF7 mail sent (#140: no de-duplication, 2 each means a duplicate lead)' % (n, res['dispatched'], res['mail_sent']))

for key, res in data.get('consent', {}).items():
    name, decision = key.split(':')
    scenario = 'S4 consent'
    subject = '%s %s' % (name, decision)
    check(res['status'] == 'sent', scenario, subject, 'provider reports the submission as sent (%s)' % res['status'])
    if decision == 'accept':
        check(res['decision_to_submit_ms'] < 2000, scenario, subject, 'submit %d ms after the accept click' % res['decision_to_submit_ms'])
        check(len(res['leads']) == 1 and res['leads'][0].get('gclid') == 'G5', scenario, subject, 'stored lead carries gclid G5')
        rec = one_record(res)
        check(rec is not None and rec['entry'].get('ct_gclid') == 'G5', scenario, subject, 'provider entry carries ct_gclid G5 (%s)' % (rec['entry'].get('ct_gclid') if rec else None))
    else:
        check(res['leads'] == [] and res['dispatched'] == 0, scenario, subject, 'no ClickTrail row and no dispatch (%d rows, %d dispatched)' % (len(res['leads']), res['dispatched']))
        stray = {}
        for rec in res['records']:
            stray.update({k: v for k, v in rec['entry'].items() if v})
            stray.update({k: v for k, v in rec['meta'].items() if v and k.startswith('ct_')})
        check(not stray, scenario, subject, 'provider records hold no attribution values (%s)' % (stray or 'none'))
        check('attribution' not in {c.split('=')[0] for c in res['cookie']}, scenario, subject, 'no attribution cookie written (%s)' % res['cookie'])

sa = data.get('script_attrs')
if sa:
    scenario = 'S5 script_attrs'
    for handle, n in sa['tag_counts'].items():
        check(n == 1, scenario, handle, 'exactly one src tag (%d)' % n)
    check(sa['src_tags_total'] == 4, scenario, 'all', 'four clicutcl-*.js src tags in the page (%d)' % sa['src_tags_total'])
    for handle, counts in sa['attribute_counts'].items():
        check(bool(counts) and all(v == 1 for v in counts.values()), scenario, handle, 'five exclusion attributes, each exactly once (%s)' % counts)
    check(sa['consent_snippet_nowprocket'], scenario, 'consent snippet', 'inline consent default carries nowprocket (%s)' % sa['consent_snippet_tag'])
    check('data-no-defer="1"' in (sa['consent_snippet_tag'] or ''), scenario, 'consent snippet', 'inline consent default carries data-no-defer="1"')
    c, g = sa['index_consent_gtag_default'], sa['index_gtm_bootstrap']
    check(c >= 0 and g >= 0 and c < g, scenario, '#147 order', "consent default (offset %d) precedes the GTM bootstrap (offset %d)" % (c, g))

# Plugin diagnostics seen in the 2026-10-31 run and reported, not yet fixed: a FINDING while present, a PASS when gone.
KNOWN_PLUGIN_DIAGNOSTICS = {
    'class-events-logger.php': 'Events_Logger::clear_event_cookie() calls setcookie() from the wp_head callback render_server_events() (priority 5), '
                               'after output started, on every page view while consent is required and not granted: the warning is raised and the cookie clear cannot take effect',
}
log = out / 'debug.log'
if log.exists():
    text = log.read_text(errors='replace').splitlines()
    lines = [l for l in text if re.search(r'PHP (Notice|Warning|Deprecated|Fatal error|Parse error)', l)]
    ours = [l for l in lines if 'click-trail-handler' in l]
    known = {sig: [l for l in ours if sig in l] for sig in KNOWN_PLUGIN_DIAGNOSTICS}
    unknown = [l for l in ours if not any(sig in l for sig in KNOWN_PLUGIN_DIAGNOSTICS)]
    check(not unknown, 'debug.log', 'plugin', '%d unexplained PHP notice/warning/deprecated/fatal line(s) from the plugin' % len(unknown))
    for l in unknown[:20]:
        print('        ' + l[:300])
    for sig, hits in known.items():
        record('FINDING' if hits else 'PASS', 'debug.log', sig, '%d line(s): %s' % (len(hits), KNOWN_PLUGIN_DIAGNOSTICS[sig]))
        if hits:
            print('        ' + hits[0][:300])
    others = [l for l in lines if l not in ours]
    # Not under the plugin path, but caused by it: ClickTrail registers the legacy alias, so Fluent Forms emits the deprecation.
    legacy = [l for l in others if 'fluentform_insert_response_data' in l]
    record('FINDING' if legacy else 'PASS', 'debug.log', 'fluentform_insert_response_data',
           '%d "Hook fluentform_insert_response_data is deprecated" notice(s): Fluent_Forms_Adapter registers the legacy alias next to the slash hook (one notice per Fluent submission with WP_DEBUG)' % len(legacy))
    record('INFO', 'debug.log', 'other', '%d other PHP diagnostics line(s) from WordPress, form plugins or wp-cli' % (len(others) - len(legacy)))
    record('INFO', 'debug.log', 'plugin notes', '%d "consent override by caller" debug line(s) (not PHP diagnostics; WP_DEBUG-only audit log)' % sum('consent override by caller' in l for l in text))
    (out / 'debug-log-other.txt').write_text('\n'.join(others))
else:
    record('FAIL', 'debug.log', 'plugin', 'debug.log missing: WP_DEBUG_LOG was not collected')

(out / 'results.json').write_text(json.dumps(results, indent=1))
failures = [r for r in results if r['status'] == 'FAIL']
print('%d PASS, %d FINDING, %d FAIL' % (sum(r['status'] == 'PASS' for r in results), sum(r['status'] == 'FINDING' for r in results), len(failures)))
sys.exit(1 if failures else 0)
