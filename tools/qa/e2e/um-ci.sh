#!/usr/bin/env bash
# Ultimate Member registration runtime evidence. Usage: um-ci.sh [plugin-root]
# Needs podman, curl, python3 with playwright + chromium. Evidence lands in $CT_E2E_OUT (default: a temp dir).
# Exit 1 on any FAIL printed by um.py. Wrap in `flock /tmp/ct-e2e.lock` when other runs share the pod.
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

"$HERE/um-setup.sh"
{
  echo "wordpress $("$HERE/wp.sh" core version)"
  podman exec ct-e2e-wp php -r 'echo "php ", PHP_VERSION, "\n";'
  "$HERE/wp.sh" plugin list --fields=name,status,version --format=csv
  podman image inspect --format '{{.RepoTags}} {{.Digest}}' docker.io/library/wordpress:php8.3-apache docker.io/library/mariadb:lts
} > "$OUT/versions.txt"
python3 "$HERE/um.py" "$OUT"
echo "evidence: $OUT"
