#!/usr/bin/env bash
# WooCommerce privacy-lifecycle E2E pod: the base pod from setup.sh plus WooCommerce (no browser needed).
# Usage: woo-setup.sh [plugin-root]. Teardown: podman pod rm -f ct-e2e && podman volume rm ct-e2e-wp ct-e2e-db
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export CT_E2E_PLUGIN="${1:-${CT_E2E_PLUGIN:-$(cd "$HERE/../../.." && pwd)}}"
WOO_VERSION=11.2.2
wp() { "$HERE/wp.sh" "$@"; }

"$HERE/setup.sh"
wp plugin install woocommerce --version="$WOO_VERSION" --activate >/dev/null
wp config set WP_DEBUG true --raw >/dev/null
wp config set WP_DEBUG_LOG true --raw >/dev/null
wp config set WP_DEBUG_DISPLAY false --raw >/dev/null
wp eval 'WC_Install::install();' >/dev/null
echo "woocommerce ready"
