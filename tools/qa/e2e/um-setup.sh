#!/usr/bin/env bash
# Ultimate Member E2E pod: the base pod from setup.sh plus Ultimate Member (free), WP_DEBUG_LOG on.
# Usage: um-setup.sh [plugin-root]. Teardown: podman pod rm -f ct-e2e && podman volume rm ct-e2e-wp ct-e2e-db
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export CT_E2E_PLUGIN="${1:-${CT_E2E_PLUGIN:-$(cd "$HERE/../../.." && pwd)}}"
ULTIMATE_MEMBER_VERSION=2.14.1
wp() { "$HERE/wp.sh" "$@"; }

"$HERE/setup.sh"
wp plugin install ultimate-member --version="$ULTIMATE_MEMBER_VERSION" --activate >/dev/null
# UM installs its default forms and pages only for a user who can manage_options; activation from wp-cli has none.
wp --user=admin eval 'UM()->setup()->run_setup(); UM()->setup()->install_default_pages();' >/dev/null
wp config set WP_DEBUG true --raw >/dev/null
wp config set WP_DEBUG_LOG true --raw >/dev/null
wp config set WP_DEBUG_DISPLAY false --raw >/dev/null
echo "ultimate-member ready"
