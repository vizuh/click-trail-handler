#!/usr/bin/env bash
# Easy Digital Downloads E2E pod: the base pod from setup.sh plus EDD, one priced download and the Store (manual) gateway.
# Usage: edd-setup.sh [plugin-root]. Teardown: podman pod rm -f ct-e2e && podman volume rm ct-e2e-wp ct-e2e-db
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export CT_E2E_PLUGIN="${1:-${CT_E2E_PLUGIN:-$(cd "$HERE/../../.." && pwd)}}"
EDD_VERSION=3.7.3
wp() { "$HERE/wp.sh" "$@"; }

"$HERE/setup.sh"
wp plugin install easy-digital-downloads --version="$EDD_VERSION" --activate >/dev/null
wp config set WP_DEBUG true --raw >/dev/null
wp config set WP_DEBUG_LOG true --raw >/dev/null
wp config set WP_DEBUG_DISPLAY false --raw >/dev/null
podman cp "$HERE/edd-fixtures.php" ct-e2e-wp:/var/www/html/ct-edd-fixtures.php
podman exec ct-e2e-wp chown 33:33 /var/www/html/ct-edd-fixtures.php
wp eval-file ct-edd-fixtures.php
echo "edd ready"
