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

def has_a_value(d):
    return {k: v for k, v in d.items() if any(a in str(v) for a in ('AAA', 'AFB', 'a_src'))}


def all_blank(res):
    """No attribution value anywhere in the provider record or the stored lead."""
    stray = {}
    for rec in res['records']:
        stray.update({k: v for k, v in rec['entry'].items() if v})
        stray.update({k: v for k, v in rec['meta'].items() if v and k.startswith('ct_')})
    return stray


for name, res in data.get('cache_leak', {}).items():
    scenario = 'S3 cache_leak'
    # (a) render layer: the cached snapshot is visitor-neutral.
    render = res['render']
    check(render['cookie_A'].get('gclid') == 'AAA', scenario, name + ' render', "setup: visitor A's cookie holds gclid=AAA")
    check(not render['ct_inputs_non_empty'], scenario, name + ' render', "with A's cookie the rendered ct_* inputs are all empty (%s)" % (render['ct_inputs_non_empty'] or 'none'))
    record('INFO', scenario, name + ' render', "A's values anywhere in the HTML (inline config included): %s" % (render['a_values_anywhere'] or 'none'))

    # (b) submit layer: force the old vector (render filter on), serve A's snapshot to B.
    sub = res['submit']
    forced = res['forced_render']
    if name.startswith('wpforms'):
        record('INFO', scenario, name + ' submit', "Lite fills no field at render (hidden is a Pro field); A's values were planted in the posted inputs instead (rendered with filter on: %s)" % (forced['ct_inputs_non_empty'] or 'none'))
    else:
        check(forced['carries_A'] and bool(forced['ct_inputs_non_empty']), scenario, name + ' submit', "setup: with the render filter on, A's snapshot carries A's values (%s)" % list(forced['ct_inputs_non_empty'].items())[:3])
        record('INFO', scenario, name + ' submit', "B's DOM held A's values for: %s" % (sorted(has_a_value(sub['dom_before'])) or 'none'))
    check(sub['status'] == 'sent', scenario, name + ' submit', 'submit succeeded (%s)' % sub['status'])
    check(sub['cookie_before'].get('gclid') in (None, '') and sub['cookie_before'].get('fbclid') == 'BFB', scenario, name + ' submit', "setup: B's cookie lacks gclid and holds fbclid=BFB (%s)" % sub['cookie_before'])
    rec = one_record(sub)
    lead = sub['leads'][0] if sub['leads'] else {}
    entry = rec['entry'] if rec else {}
    check(entry.get('ct_fbclid') == 'BFB' and entry.get('ct_lt_source') == 'b_src', scenario, name + ' submit', "provider record holds B's own values (fbclid=%r lt_source=%r)" % (entry.get('ct_fbclid'), entry.get('ct_lt_source')))
    check(lead.get('fbclid') == 'BFB' and lead.get('lt_source') == 'b_src', scenario, name + ' submit', "stored lead holds B's own values (fbclid=%r lt_source=%r)" % (lead.get('fbclid'), lead.get('lt_source')))
    check(entry.get('ct_gclid', '') == '' and entry.get('ct_ft_gclid', '') == '' and entry.get('ct_lt_gclid', '') == '', scenario, name + ' submit', "provider record is empty for the keys B's cookie lacks (gclid=%r)" % entry.get('ct_gclid'))
    check(lead.get('gclid', '') == '' and lead.get('ft_gclid', '') == '', scenario, name + ' submit', "stored lead is empty for the keys B's cookie lacks (gclid=%r)" % lead.get('gclid'))
    leaked = dict(has_a_value(entry), **{'lead.' + k: v for k, v in has_a_value(lead).items()})
    if name == 'fluent' and rec:
        leaked.update({'meta.' + k: v for k, v in has_a_value(rec['meta']).items()})
    check(not leaked, scenario, name + ' submit', "no value of visitor A anywhere in the stored lead or provider record (%s)" % (leaked or 'none'))

    # (c) consent required, not granted, stale snapshot posted.
    for variant, cres in res['consent'].items():
        subject = '%s consent %s' % (name, variant)
        check(cres['status'] == 'sent', scenario, subject, 'submit succeeded (%s)' % cres['status'])
        check(cres['leads'] == [] and cres['dispatched'] == 0, scenario, subject, 'no ClickTrail row and no dispatch (%d rows, %d dispatched)' % (len(cres['leads']), cres['dispatched']))
        stray = all_blank(cres)
        check(not stray, scenario, subject, 'every ct_* value in the provider record is blank (%s)' % (stray or 'none'))

nr = data.get('cf7_no_references')
if nr:
    scenario = 'S7 cf7_no_references'
    check(nr['status'] == 'sent', scenario, 'cf7', 'provider reports the submission as sent (%s)' % nr['status'])
    check(not nr['served_ct_inputs'] and not nr['dom_ct_inputs'], scenario, 'cf7', 'no ct_* input in the served HTML or the DOM (%s %s)' % (nr['served_ct_inputs'], nr['dom_ct_inputs']))
    check(not nr['posted_ct_fields'], scenario, 'cf7', 'no ct_* field in the CF7 posted data (%s)' % nr['posted_ct_fields'])
    check(len(nr['mail_bodies']) >= 1 and not any('ct_' in b for b in nr['mail_bodies']), scenario, 'cf7', 'mail sent (%d) with no ct_* line' % len(nr['mail_bodies']))
    check(any('GCLID: G1' in b for b in nr['referenced_mail_bodies']), scenario, 'cf7 referenced form', 'mail line `GCLID: [ct_gclid]` resolves to the visit gclid (%s)' % [b[-60:] for b in nr['referenced_mail_bodies']])
    check(len(nr['leads']) == 1 and nr['leads'][0].get('gclid') == 'G1', scenario, 'cf7', 'ClickTrail lead carries the visit gclid G1 via the cookie fallback (%d lead(s))' % len(nr['leads']))
    check(nr['dispatched'] == 1, scenario, 'cf7', 'one dispatched form_submission (%d)' % nr['dispatched'])

dbl = data.get('double_submit', {})
for variant, res in dbl.items():
    n = res['leads']
    record('FINDING' if n > 1 else 'PASS', 'S6 double_submit', variant, '%d form_submission row(s), %d dispatched, %d CF7 mail sent (#140/#178: more than 1 lead or dispatch means a duplicate; CF7 mails are not de-duplicated)' % (n, res['dispatched'], res['mail_sent']))

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
