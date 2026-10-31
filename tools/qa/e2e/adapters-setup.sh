#!/usr/bin/env bash
# Form-adapter E2E pod: the base pod from setup.sh plus Fluent Forms, WPForms Lite, WP_DEBUG_LOG and fixtures.
# Usage: adapters-setup.sh [plugin-root]. Teardown: podman pod rm -f ct-e2e && podman volume rm ct-e2e-wp ct-e2e-db
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export CT_E2E_PLUGIN="${1:-${CT_E2E_PLUGIN:-$(cd "$HERE/../../.." && pwd)}}"
FLUENTFORM_VERSION=6.2.16
WPFORMS_VERSION=2.0.3.1
wp() { "$HERE/wp.sh" "$@"; }

"$HERE/setup.sh"
wp plugin install fluentform --version="$FLUENTFORM_VERSION" --activate >/dev/null
wp plugin install wpforms-lite --version="$WPFORMS_VERSION" --activate >/dev/null
wp config set WP_DEBUG true --raw >/dev/null
wp config set WP_DEBUG_LOG true --raw >/dev/null
wp config set WP_DEBUG_DISPLAY false --raw >/dev/null
podman cp "$HERE/fixtures.php" ct-e2e-wp:/var/www/html/ct-fixtures.php
podman exec ct-e2e-wp chown 33:33 /var/www/html/ct-fixtures.php
wp eval-file ct-fixtures.php
echo "adapters ready"
