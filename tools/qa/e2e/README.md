# Attribution round trip (local, throwaway)

Proves Google A, then Meta B, then a Contact Form 7 submit and a Calendly booking end up with the right
attribution in what ClickTrail stores and dispatches, not only in the URL or `dataLayer`.

```sh
tools/qa/e2e/ci.sh [plugin-root]      # whole round trip + assertions; tears the pod down; exit 1 on any mismatch
```

CI runs the same script on every pull request and push to `main`
(`.github/workflows/e2e-attribution.yml`, `ubuntu-24.04`, podman + Playwright 1.59.0). Against plugin
1.10.4 code 10 of its 15 checks fail; against 1.10.5 all pass.

`ci.sh` runs, in order: `setup.sh` (podman pod `ct-e2e` on 127.0.0.1:8088, plugin mounted read-only, Contact
Form 7 pinned), `run.py` (Google A then Meta B, links, CF7 submit), `webhook.py` (signed Calendly
`invitee.created` with the browser's stamp), `dual.py` (both cookies with different click IDs), then
`verify.py` over the browser JSON, the stored `wp_clicutcl_events` rows and the captured dispatches. Set
`CT_E2E_OUT` to keep the evidence directory. `wp.sh` runs wp-cli inside the pod for manual digging.

Expected for 1.10.5: stamp `ct1;fbclid=FB`; stored and dispatched lead `lt_campaign=m1`, `fbclid=FB`,
`attribution_selected_click_id=FB`; dispatched `book_appointment` with `fbclid=FB` and no `gclid`; with both
cookies present the canonical one wins in the browser, the rendered field and the stored lead.

## Form adapters (Fluent Forms, WPForms Lite, Contact Form 7)

```sh
tools/qa/e2e/adapters-ci.sh [plugin-root]   # same pod + Fluent Forms 6.2.16 + WPForms Lite 2.0.3.1, WP_DEBUG_LOG on
```

`adapters-setup.sh` extends `setup.sh` (pinned plugins, `fixtures.php` forms and one page per provider,
`mu/ct-e2e-provider-capture.php` recording the CF7 posted data and the WPForms processed fields, because CF7 has no
storage and WPForms Lite has no entries table). `adapters.py` drives Chromium through the scenarios and writes
`adapters.json`; `verify_adapters.py` judges it (`PASS`, `FAIL` exits 1, `FINDING` is a recorded known defect such as
the cache leak of #152 or the double submit of #140). Run a subset with
`python3 tools/qa/e2e/adapters.py <out-dir> landing returning` against a pod from `adapters-setup.sh`.
Results of a run: `EVIDENCE-<date>.md`.

Notes:

- `mu/ct-e2e-capture.php` intercepts the server-side dispatch to `https://capture.invalid/collect` and
  short-circuits `wp_mail`. Never install it on a real site.
- The browser masks `navigator.webdriver`; ClickTrail skips automated browsers by design.
- Consent is not required in this setup; consent transitions are covered by `tools/qa/cmp-transition.test.js`.
- On SELinux hosts mount this checkout into other containers with `:z`, not `:Z`. A private label locks the
  WordPress container out of the plugin files (Apache 403).
- A replayed webhook proves ClickTrail's mapping only. Whether Calendly keeps `salesforce_uuid` through a real
  booking still needs a live booking.
