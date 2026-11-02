#!/usr/bin/env bash
# Easy Digital Downloads runtime evidence (real EDD, Store gateway, Chromium). Usage: edd-ci.sh [plugin-root]
# Shares the one ct-e2e pod: wrap in `flock /tmp/ct-e2e.lock` when other runs may be active.
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

"$HERE/edd-setup.sh"
python3 "$HERE/edd.py" "$OUT"
podman exec ct-e2e-wp cat /var/www/html/wp-content/debug.log > "$OUT/debug.log" 2>/dev/null || : > "$OUT/debug.log"
{
  echo "wordpress $("$HERE/wp.sh" core version)"
  podman exec ct-e2e-wp php -r 'echo "php ", PHP_VERSION, "\n";'
  "$HERE/wp.sh" plugin list --fields=name,status,version --format=csv
  podman image inspect --format '{{.RepoTags}} {{.Digest}}' docker.io/library/wordpress:php8.3-apache docker.io/library/mariadb:lts
  podman exec ct-e2e-db mariadbd --version 2>/dev/null || true
} > "$OUT/versions.txt"
python3 "$HERE/verify_edd.py" "$OUT"
echo "evidence: $OUT"
