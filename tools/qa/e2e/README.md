# Attribution round trip (local, throwaway)

Proves Google A, then Meta B, then a Contact Form 7 submit and a Calendly booking end up with the right
attribution in what ClickTrail stores and dispatches, not only in the URL or `dataLayer`.

```sh
tools/qa/e2e/setup.sh                 # podman pod ct-e2e on 127.0.0.1:8088; mounts this plugin read-only
python3 tools/qa/e2e/run.py > /tmp/ct-e2e.json    # Playwright Chromium: two visits, links, form submit
python3 tools/qa/e2e/webhook.py /tmp/ct-e2e.json  # signed Calendly invitee.created with the browser's stamp
podman exec ct-e2e-wp tail -2 /var/www/html/wp-content/ct-capture.jsonl   # dispatched lead + booking
podman pod rm -f ct-e2e && podman volume rm ct-e2e-wp ct-e2e-db
```

Expected for 1.10.5: stamp `ct1;fbclid=FB`; stored and dispatched lead `lt_campaign=m1`, `fbclid=FB`,
`attribution_selected_click_id=FB`; dispatched `book_appointment` with `fbclid=FB` and no `gclid`.

Notes:

- `mu/ct-e2e-capture.php` intercepts the server-side dispatch to `https://capture.invalid/collect` and
  short-circuits `wp_mail`. Never install it on a real site.
- The browser masks `navigator.webdriver`; ClickTrail skips automated browsers by design.
- Consent is not required in this setup; consent transitions are covered by `tools/qa/cmp-transition.test.js`.
- On SELinux hosts mount this checkout into other containers with `:z`, not `:Z`. A private label locks the
  WordPress container out of the plugin files (Apache 403).
- A replayed webhook proves ClickTrail's mapping only. Whether Calendly keeps `salesforce_uuid` through a real
  booking still needs a live booking.
