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

## Cookie scope across hosts (#154)

```sh
flock /tmp/ct-e2e.lock tools/qa/e2e/cookie-scope.sh [plugin-root]   # own pod run, tears down; exit 1 on any mismatch
```

Serves the one WordPress as `www.example.test` and `example.test` (`mu/ct-e2e-dynamic-host.php` answers
`home`/`siteurl` with the request Host while option `ct_e2e_dynamic_host` is set; Chromium maps both names to
127.0.0.1 with `--host-resolver-rules`). `cookie_scope.py` lands on `www` with `?gclid=GA`, opens `example.test`,
reads the CF7 hidden `ct_gclid` and submits; the script runs it once with `cookie_scope=registrable` (cookie
`domain=.example.test`, apex field `GA`, stored lead carries it) and once with `host` (cookie host-only, apex field empty).

## Attribution cookie re-issue (#141)

```sh
flock /tmp/ct-e2e.lock tools/qa/e2e/cookie-reissue.sh [plugin-root]   # own pod run, tears down; exit 1 on any mismatch
```

Same two-host pod as the cookie-scope run. `cookie_reissue.py` proves on the wire that page GET/HEAD responses never carry `Set-Cookie`
(with or without a valid `attribution` cookie and consent cookie), that `POST /clicutcl/v2/cookie-refresh` answers with exactly one
`Set-Cookie: attribution=<same value>; Max-Age=7776000; Path=/; Domain=example.test; SameSite=Lax` (and the gates: token, consent from the
request cookie, setting, invalid and oversized values), and that Chromium ends with one `attribution` cookie of the right domain and a 90-day
lifetime although every script write of that cookie is capped to 24 hours by an init script (Safari's cap, emulated: the browser does not
cap, so an uncapped lifetime is only possible if the server's cookie was applied). A control run with the setting off keeps the 24-hour cap.
Chromium reports no response for a `keepalive` fetch, so the server-side view comes from `mu/ct-e2e-set-cookie-log.php` (`shutdown` hook
recording status and the `Set-Cookie` headers PHP sent). Raw requests are rate limited (60 a minute per IP, scope `cookie_reissue`): wait a
minute between manual reruns against one pod. Safari itself is not exercised.

## Form adapters (Fluent Forms, WPForms Lite, SureForms, Contact Form 7, MetForm)

```sh
tools/qa/e2e/adapters-ci.sh [plugin-root]   # same pod + Fluent Forms 6.2.16 + WPForms Lite 2.0.3.1 + SureForms 2.13.0 + Elementor 4.3.5 + MetForm 4.3.4, WP_DEBUG_LOG on
```

`adapters-setup.sh` extends `setup.sh` (pinned plugins, `fixtures.php` forms and one page per provider,
`mu/ct-e2e-provider-capture.php` recording the CF7 posted data and the WPForms processed fields, because CF7 has no
storage and WPForms Lite has no entries table; SureForms entries are read from `wp_srfm_entries`, its notification mail is captured by the same `pre_wp_mail` observer). `adapters.py` drives Chromium through the scenarios and writes
`adapters.json`; `verify_adapters.py` judges it (`PASS`, `FAIL` exits 1, `FINDING` is a recorded known defect such as
the cache leak of #152 or the double submit of #140). Run a subset with
`python3 tools/qa/e2e/adapters.py <out-dir> landing returning` against a pod from `adapters-setup.sh`.
`mu/ct-e2e-render-toggle.php` (option `ct_e2e_render_values`) forces the #152 render filter on so the cache scenario can build
the stale-snapshot vector and prove the submit layer blanks it.
MetForm is driven in both builders (`metform` = the standalone block builder, `metform_el` = the Elementor builder), each with
six `ct_<key>` hidden inputs. Its entries are `metform-entry` posts, so `adapters_lib.delta()` reads them (form data plus the
`ct_*` post meta ClickTrail writes) straight from the database, and the observer in `mu/ct-e2e-provider-capture.php` logs the
raw posted `ct_*` data on `metform_before_store_form_data`, i.e. what the browser sent before the refresh. The Elementor builder
posts its Preact state, not the DOM, so the cache scenario plants visitor A's values in the served template for it.
Results of a run: `EVIDENCE-<date>.md` (`EVIDENCE-2026-11-02-sureforms.md` for SureForms).

Notes:

- `mu/ct-e2e-capture.php` intercepts the server-side dispatch to `https://capture.invalid/collect` and
  short-circuits `wp_mail`. Never install it on a real site.
- The browser masks `navigator.webdriver`; ClickTrail skips automated browsers by design.
- Consent is not required in this setup; consent transitions are covered by `tools/qa/cmp-transition.test.js`.
- On SELinux hosts mount this checkout into other containers with `:z`, not `:Z`. A private label locks the
  WordPress container out of the plugin files (Apache 403).
- A replayed webhook proves ClickTrail's mapping only. Whether Calendly keeps `salesforce_uuid` through a real
  booking still needs a live booking.
