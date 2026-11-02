#!/usr/bin/env bash
# Two-host cookie scope proof (#154, specs/007-cookie-scope SC-004). Usage: cookie-scope.sh [plugin-root]
# Serves one WordPress as www.example.test and example.test (Chromium host-resolver rules + mu/ct-e2e-dynamic-host.php).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export CT_E2E_PLUGIN="${1:-${CT_E2E_PLUGIN:-$(cd "$HERE/../../.." && pwd)}}"
OUT="${CT_E2E_OUT:-$(mktemp -d)}"
mkdir -p "$OUT"
cleanup() {
  podman pod rm -f ct-e2e >/dev/null 2>&1 || true
  podman volume rm -f ct-e2e-wp ct-e2e-db >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$HERE/setup.sh"
"$HERE/wp.sh" option update ct_e2e_dynamic_host 1 >/dev/null
"$HERE/wp.sh" option update clicutcl_attribution_settings \
  '{"enable_attribution":1,"require_consent":0,"cookie_days":90,"enable_js_injection":1,"cookie_scope":"registrable"}' --format=json >/dev/null
python3 "$HERE/cookie_scope.py" registrable > "$OUT/cookie-scope-registrable.json"
"$HERE/wp.sh" db query "SELECT event_data FROM wp_clicutcl_events WHERE event_type='form_submission' ORDER BY id" \
  --skip-column-names > "$OUT/cookie-scope-registrable-leads.jsonl"
"$HERE/wp.sh" db query "DELETE FROM wp_clicutcl_events" >/dev/null
"$HERE/wp.sh" option update clicutcl_attribution_settings \
  '{"enable_attribution":1,"require_consent":0,"cookie_days":90,"enable_js_injection":1,"cookie_scope":"host"}' --format=json >/dev/null
python3 "$HERE/cookie_scope.py" host > "$OUT/cookie-scope-host.json"
"$HERE/wp.sh" db query "SELECT event_data FROM wp_clicutcl_events WHERE event_type='form_submission' ORDER BY id" \
  --skip-column-names > "$OUT/cookie-scope-host-leads.jsonl"
python3 - "$OUT" <<'PY'
import json, sys
out = sys.argv[1]
fail = 0
def check(ok, label):
    global fail
    print(('ok    ' if ok else 'FAIL  ') + label)
    fail += 0 if ok else 1
for mode in ('registrable', 'host'):
    j = json.load(open(f'{out}/cookie-scope-{mode}.json'))
    leads = [json.loads(l) for l in open(f'{out}/cookie-scope-{mode}-leads.jsonl') if l.strip()]
    blob = json.dumps(leads)
    if mode == 'registrable':
        check(j['landing_cookie_domain'] == '.example.test', 'registrable: attribution cookie domain is .example.test (%s)' % j['landing_cookie_domain'])
        check(j['apex_hidden_gclid'] == 'GA', 'registrable: apex form field carries the www click (%r)' % j['apex_hidden_gclid'])
        check('GA' in blob and 'gclid' in blob, 'registrable: stored apex lead carries gclid GA')
    else:
        check(j['landing_cookie_domain'] == 'www.example.test', 'host: attribution cookie is host-only (%s)' % j['landing_cookie_domain'])
        check(j['apex_hidden_gclid'] in ('', None), 'host: apex form field is empty (%r)' % j['apex_hidden_gclid'])
        check('"GA"' not in blob, 'host: stored apex lead does not carry the www click')
    check('sent' in (j['cf7_status'] or ''), mode + ': CF7 submit on the apex reports sent')
print('%d failure(s)' % fail)
sys.exit(1 if fail else 0)
PY
