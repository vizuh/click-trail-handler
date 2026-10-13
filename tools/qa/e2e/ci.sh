#!/usr/bin/env bash
# Attribution round trip with assertions (CI and local). Usage: ci.sh [plugin-root]
# Needs podman, curl, python3 with playwright + chromium. Evidence lands in $CT_E2E_OUT (default: a temp dir).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export CT_E2E_PLUGIN="${1:-${CT_E2E_PLUGIN:-$(cd "$HERE/../../.." && pwd)}}"
OUT="${CT_E2E_OUT:-$(mktemp -d)}"
mkdir -p "$OUT"
cleanup() {
  podman logs ct-e2e-wp > "$OUT/wordpress.log" 2>&1 || true
  podman pod rm -f ct-e2e >/dev/null 2>&1 || true
  podman volume rm -f ct-e2e-wp ct-e2e-db >/dev/null 2>&1 || true
}
trap cleanup EXIT

"$HERE/setup.sh"
python3 "$HERE/run.py" > "$OUT/journey.json"
python3 "$HERE/webhook.py" "$OUT/journey.json" > "$OUT/webhook.txt"
python3 "$HERE/dual.py" > "$OUT/dual.json"
"$HERE/wp.sh" db query "SELECT event_data FROM wp_clicutcl_events WHERE event_type='form_submission' ORDER BY id" \
  --skip-column-names > "$OUT/leads.jsonl"
podman exec ct-e2e-wp cat /var/www/html/wp-content/ct-capture.jsonl > "$OUT/capture.jsonl"
python3 "$HERE/verify.py" "$OUT"
echo "evidence: $OUT"
