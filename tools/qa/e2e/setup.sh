#!/usr/bin/env bash
# Throwaway WordPress pod for ClickTrail E2E. Usage: setup.sh [plugin-root]; teardown: podman pod rm -f ct-e2e && podman volume rm ct-e2e-wp ct-e2e-db
set -euo pipefail
PLUGIN="${1:-$(cd "$(dirname "$0")/../../.." && pwd)}"; HERE="$(cd "$(dirname "$0")" && pwd)"
podman pod exists ct-e2e && { podman pod rm -f ct-e2e >/dev/null; podman volume rm -f ct-e2e-wp ct-e2e-db >/dev/null 2>&1 || true; }
podman pod create --name ct-e2e -p 127.0.0.1:8088:80 >/dev/null
podman run -d --pod ct-e2e --name ct-e2e-db -v ct-e2e-db:/var/lib/mysql \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp localhost/mariadb:lts >/dev/null
podman run -d --pod ct-e2e --name ct-e2e-wp -v ct-e2e-wp:/var/www/html \
  -v "$PLUGIN":/var/www/html/wp-content/plugins/click-trail-handler:ro,z \
  -v "$HERE/mu":/var/www/html/wp-content/mu-plugins:ro,z \
  -e WORDPRESS_DB_HOST=127.0.0.1 -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  docker.io/library/wordpress:php8.3-apache >/dev/null
wp() { podman run --rm --pod ct-e2e -v ct-e2e-wp:/var/www/html -v "$PLUGIN":/var/www/html/wp-content/plugins/click-trail-handler:ro,z \
  -v "$HERE/mu":/var/www/html/wp-content/mu-plugins:ro,z --user 33:33 \
  -e WORDPRESS_DB_HOST=127.0.0.1 -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  docker.io/library/wordpress:cli-php8.3 wp "$@"; }
for i in $(seq 1 60); do curl -s -o /dev/null http://127.0.0.1:8088/ && break; sleep 2; done
for i in $(seq 1 30); do wp core is-installed 2>/dev/null && break; wp core install --url=http://127.0.0.1:8088 --title=CT --admin_user=admin --admin_password=admin --admin_email=a@example.test --skip-email >/dev/null 2>&1 && break; sleep 2; done
wp plugin install contact-form-7 --activate >/dev/null
wp plugin activate click-trail-handler >/dev/null
wp option update clicutcl_attribution_settings '{"enable_attribution":1,"require_consent":0,"cookie_days":90,"enable_js_injection":1,"enable_link_decoration":1,"link_allowed_domains":"example.test,calendly.com"}' --format=json >/dev/null
wp option update clicutcl_server_side '{"enabled":1,"endpoint_url":"https://capture.invalid/collect","adapter":"generic","timeout":5}' --format=json >/dev/null
wp eval '$s = CLICUTCL\Tracking\Settings::get(); $s["external_forms"]["providers"]["calendly"] = array("enabled" => 1, "secret" => "e2e-secret"); update_option(CLICUTCL\Tracking\Settings::OPTION, $s);'
FORM=$(wp post list --post_type=wpcf7_contact_form --field=ID | head -1)
wp post create --post_type=page --post_status=publish --post_title=Landing --post_name=landing \
  --post_content="<p><a id=\"apply\" href=\"https://apply.example.test/form?ref=site\">Apply</a> <a id=\"book\" href=\"https://calendly.com/acme/intro\">Book</a></p>[contact-form-7 id=\"$FORM\"]" >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
echo "ready form=$FORM"
