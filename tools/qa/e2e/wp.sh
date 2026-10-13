#!/usr/bin/env bash
# wp-cli inside the ct-e2e pod. Usage: wp.sh <wp args>; CT_E2E_PLUGIN overrides the plugin root.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="${CT_E2E_PLUGIN:-$(cd "$HERE/../../.." && pwd)}"
exec podman run --rm --pod ct-e2e -v ct-e2e-wp:/var/www/html \
  -v "$PLUGIN":/var/www/html/wp-content/plugins/click-trail-handler:ro,z \
  -v "$HERE/mu":/var/www/html/wp-content/mu-plugins:ro,z --user 33:33 \
  -e WORDPRESS_DB_HOST=127.0.0.1 -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  docker.io/library/wordpress:cli-php8.3 wp "$@"
