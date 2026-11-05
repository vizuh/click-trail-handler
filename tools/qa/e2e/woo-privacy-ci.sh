#!/usr/bin/env bash
# WooCommerce privacy lifecycle runtime evidence (real WooCommerce, HPOS and posts storage, wp-cli only).
# Usage: woo-privacy-ci.sh [plugin-root]. Wrap in `flock /tmp/ct-e2e.lock` when other runs may be active.
# Needs podman and python3. Evidence lands in $CT_E2E_OUT (default: a temp dir).
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

"$HERE/woo-setup.sh"
python3 "$HERE/woo_privacy.py" "$OUT"
{
  echo "wordpress $("$HERE/wp.sh" core version)"
  "$HERE/wp.sh" plugin list --fields=name,status,version --format=csv
  podman exec ct-e2e-db mariadbd --version 2>/dev/null || true
} > "$OUT/versions.txt"
python3 "$HERE/verify_woo_privacy.py" "$OUT"
echo "evidence: $OUT"
