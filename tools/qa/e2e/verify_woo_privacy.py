"""Judge woo-privacy.json. Usage: verify_woo_privacy.py <evidence-dir>

Prints PASS / FAIL per check and writes woo-privacy-results.json. Any FAIL exits 1.
"""
import json, pathlib, sys

out = pathlib.Path(sys.argv[1])
data = json.loads((out / 'woo-privacy.json').read_text())['scenarios']
results = []
MARKER = '_clicutcl_tracking_sent'


def check(ok, scenario, message):
    results.append({'status': 'PASS' if ok else 'FAIL', 'scenario': scenario, 'message': message})
    print('%-5s %s: %s' % ('PASS' if ok else 'FAIL', scenario, message))


def managed(keys):
    return [k for k in keys if k.startswith('_clicutcl_') and k != MARKER]


for sc in data:
    mode = sc['mode']
    check(sc['hpos_active'] == (mode == 'hpos'), mode, 'order storage is %s (HPOS active=%s)' % (mode, sc['hpos_active']))
    check(sc['clauses'] <= 2, mode, 'managed-meta query has %d clause(s), far below the 61-table join limit' % sc['clauses'])
    for name, st in sc['steps'].items():
        scenario = '%s/%s' % (mode, name)
        ids, before, after = st['ids'], st['before'], st['after']
        check(st['db_error'] == '', scenario, 'no database error (%r)' % st['db_error'])
        check(st['max_joins'] <= 2, scenario, 'metadata query joins at most %d table(s)' % st['max_joins'])
        for order in ('old', 'recent', 'other', 'bare'):
            check('_unrelated_keep' in after[order], scenario, 'unrelated meta kept on %s order' % order)
        res = st['result']
        if name == 'export':
            exported = sorted({int(i['data'][0]['value']) for i in res['items']})
            check(exported == sorted([ids['old'], ids['recent'], ids['bare']]), scenario, 'export covers the subject orders only (%s)' % exported)
            keys = {i['data'][1]['value'] for i in res['items']}
            check(MARKER in keys, scenario, 'export lists the sent marker')
            check(all(after[o] == before[o] for o in after), scenario, 'export deletes nothing')
        elif name == 'retention':
            for order in ('old', 'other', 'bare'):
                check(managed(after[order]) == [], scenario, '200-day-old %s order has no ClickTrail meta left (%s)' % (order, managed(after[order])))
            check(after['recent'] == before['recent'], scenario, 'recent order is untouched')
            check(MARKER in after['old'], scenario, 'old order keeps the sent marker')
            check(res['removed'] == 3 and res['failed'] == 0, scenario, 'retention removed 3 orders (%s)' % res)
        elif name == 'erase':
            for order in ('old', 'recent', 'bare'):
                check(managed(after[order]) == [], scenario, 'subject %s order has no ClickTrail meta left (%s)' % (order, managed(after[order])))
            check(MARKER in after['old'] and MARKER in after['recent'], scenario, 'erase keeps the sent marker')
            check(after['other'] == before['other'], scenario, "another customer's order is untouched")
            check(res['items_removed'] is True and res['items_retained'] is False, scenario, 'eraser reports removal (%s)' % res)
        elif name == 'purge':
            for order in ('old', 'recent', 'other', 'bare'):
                check(managed(after[order]) == [], scenario, '%s order has no ClickTrail meta left' % order)
            check(MARKER in after['old'], scenario, 'Diagnostics purge keeps the sent marker')
            check(res['removed'] == 4 and res['processed'] == 4, scenario, 'purge processed 4 orders (%s)' % res)
        elif name == 'purge_uninstall':
            for order in ('old', 'recent', 'other', 'bare'):
                check(managed(after[order]) == [] and MARKER not in after[order], scenario, '%s order has no ClickTrail meta, marker included' % order)

(out / 'woo-privacy-results.json').write_text(json.dumps(results, indent=2))
failed = [r for r in results if r['status'] == 'FAIL']
print('%d checks, %d failed' % (len(results), len(failed)))
sys.exit(1 if failed else 0)
