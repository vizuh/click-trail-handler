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

## Ultimate Member registration (#174)

```sh
flock /tmp/ct-e2e.lock tools/qa/e2e/um-ci.sh [plugin-root]   # own pod run (Ultimate Member 2.14.1 free), tears down; exit 1 on any FAIL
```

`um-setup.sh` extends `setup.sh` with the pinned plugin, `WP_DEBUG_LOG` and UM's default registration form and pages
(UM creates those only for a user who can `manage_options`, so the script runs its setup as `--user=admin`). `um.py`
drives Chromium through the real UM registration form and judges what it finds, printing `PASS` / `FAIL` / `INFO`
(any FAIL exits 1): S1 landing with `?utm_source=test&gclid=...` (user meta, one `sign_up` with `um_registration_<id>`
across the server call and the browser events route, core `ct_event_signup` cookie and printed event share the ID),
S2 consent required with accept, deny and undecided, S3 direct registration, S4 `um_registration_complete` replayed,
S5 wp-admin "Add user", S6 the real WordPress exporter and eraser callbacks plus retention and purge-all through the real
user query, S7 `WP_DEBUG_LOG`. Observations are written to `um.json`, verdicts to `results.json`. Run it with
`flock` when other agents share the pod. Results: `EVIDENCE-<date>-ultimate-member.md`.

Notes:

- `mu/ct-e2e-capture.php` intercepts the server-side dispatch to `https://capture.invalid/collect` and
  short-circuits `wp_mail`. Never install it on a real site.
- The browser masks `navigator.webdriver`; ClickTrail skips automated browsers by design.
- Consent is not required in this setup; consent transitions are covered by `tools/qa/cmp-transition.test.js`.
- On SELinux hosts mount this checkout into other containers with `:z`, not `:Z`. A private label locks the
  WordPress container out of the plugin files (Apache 403).
- A replayed webhook proves ClickTrail's mapping only. Whether Calendly keeps `salesforce_uuid` through a real
  booking still needs a live booking.

## Easy Digital Downloads (#173)

```sh
flock /tmp/ct-e2e.lock tools/qa/e2e/edd-ci.sh [plugin-root]   # same pod + EDD 3.7.3, Store (manual) gateway, WP_DEBUG_LOG on
```

`edd-setup.sh` extends `setup.sh` with EDD pinned to 3.7.3. `edd-fixtures.php` installs EDD's component tables
(`edd_install_component_database_tables()`: WP-CLI activation never fires `admin_init`, where EDD creates them, and
without them the cart session fails silently), enables only the Store gateway, and creates one priced download.
`edd.py` drives Chromium through add to cart, checkout and the receipt and writes `edd.json`; `verify_edd.py` judges it
(`PASS` / `FAIL`, exit 1 on any FAIL) and scans `debug.log` for PHP or database errors raised by ClickTrail.
Scenarios: `landing` (gclid landing, buy, two receipt reloads; order meta, one dataLayer purchase, one dispatch),
`consent_denied`, `consent_accepted`, `consent_withdrawn` (consent cookie flipped to denied after capture, attribution
cookie still sent) and `privacy` (WordPress exporter and eraser, retention on an aged order, manual purge, against real
`edd_get_orders()` queries). Run a subset with `python3 tools/qa/e2e/edd.py <out-dir> landing` against a pod from
`edd-setup.sh`. Results of a run: `EVIDENCE-<date>-edd.md`.

