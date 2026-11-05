# Runtime evidence: Ultimate Member registration attribution, plugin 1.12.0 + #174 (2026-11-02)

What ran: `tools/qa/e2e/um-ci.sh` (new) against branch `feat/ultimate-member-integration` (main `70fb918` plus the #174
change), Ultimate Member 2.14.1 (free, installed from wordpress.org inside the pod), driven by Chromium through the real UM
registration form. A local pod, not a real cache, CMP or destination; every claim below is limited to what the harness
observed. `ci.sh` and `adapters-ci.sh` were rerun on the same checkout as regression gates.

## Environment

| | |
|---|---|
| WordPress | 7.1 (image `wordpress:php8.3-apache`, `sha256:74dc0652…6f18b`) |
| PHP | 8.3.33 (NTS, Apache) |
| Database | MariaDB `lts` (`sha256:ebfd70b7…e0b`) |
| ClickTrail | 1.12.0 + #174 (mounted read-only) |
| Ultimate Member | 2.14.1 free (pinned by `um-setup.sh`), default registration form and pages created by `UM()->setup()` as `--user=admin` |
| Browser | Chromium 147.0.7727.15 headless, Playwright 1.59.0 (Python), `navigator.webdriver` masked as in the base harness |
| Host | Fedora, kernel 7.2.13, podman 5.8.9 |
| Debug | `WP_DEBUG`, `WP_DEBUG_LOG` on, `WP_DEBUG_DISPLAY` off; `debug.log` scanned after the run |
| Server-side delivery | enabled against `https://capture.invalid/collect`, intercepted by `mu/ct-e2e-capture.php` (no provider traffic) |
| Consent | not required for S1, S3, S4, S5, S6; required (plugin banner, strict) for S2 |

## Result

`um-ci.sh`: **43 checks, 43 PASS, 0 FAIL**, exit 0 (fresh pod). Regression gates on the same checkout: `ci.sh` 15 `ok`,
`0 failure(s)`; `adapters-ci.sh` 316 PASS, 6 FINDING (the known WPForms Lite entry-meta limitation), 0 FAIL.

| # | Scenario | Observed |
|---|---|---|
| S1 | Land on `/?utm_source=test&utm_medium=cpc&utm_campaign=um&gclid=GCLIDUM1`, register through the UM form (consent not required) | User created and redirected by UM. User meta holds `_clicutcl_gclid=GCLIDUM1`, `_clicutcl_lt_source=test`, `_clicutcl_lt_medium=cpc`, `_clicutcl_lt_campaign=um`, `_clicutcl_ft_source=test`, `_clicutcl_visitor_id`, `_clicutcl_session_id`, `_clicutcl_um_signup_sent=yes`; only allowlisted keys; no password. Exactly **one** `sign_up` reached the collector, `event_id` `um_registration_<user id>`, `form.platform` `ultimate-member`, `form.id` the UM form, `gclid` in its attribution, hashed email in its identity. The registration POST answered with the core `ct_event_signup` cookie carrying the same `um_registration_<id>`, the page UM redirected to printed that event, and the browser events route (`/clicutcl/v2/events/batch`) was sent the same ID: the collector still saw one `sign_up`, which is what dedupe on event name + ID predicts; the harness did not isolate why the browser copy was not delivered (it did not read the route's response) |
| S2 | Consent required (plugin banner): accept / deny / leave undecided, each after a landing with `gclid=GCLIDCONSENT` | Registration succeeds in all three. Accept: attribution stored, one `sign_up`, same event ID in the core cookie. Deny and undecided: no `_clicutcl_*` user meta at all, no `sign_up` dispatched, no `ct_event_signup` cookie, nothing printed for the browser |
| S3 | Direct registration, no landing, no attribution cookie | One `sign_up` with the deterministic ID; the only user meta is `_clicutcl_um_signup_sent` |
| S4 | `do_action( 'um_registration_complete', … )` twice more for the S1 user | Nothing dispatched, stored meta unchanged (sent marker) |
| S5 | wp-admin "Add new user" as admin (UM runs `um_user_register` with a `null` form) | User created; no `_clicutcl_*` user meta, no `sign_up` |
| S6 | Real WordPress privacy callbacks and user queries | `wp_privacy_personal_data_exporters` lists `clicutcl-user-registration` and exports the stored keys (ClickTrail keys only); the eraser removes exactly the allowlisted keys and leaves a lookalike `_clicutcl_other_plugin` and the user; `purge_expired_user_metadata( 90 )` clears a user registered 200 days ago and keeps a new one (WP `date_query` plus the anchored key regexp as `meta_query` `compare_key`); `purge_all_user_metadata( 1 )` clears the rest page by page and leaves unrelated meta |
| S7 | `WP_DEBUG_LOG` | No PHP warning, notice, deprecation or fatal from the plugin. The log holds only ClickTrail's existing debug lines `consent override by caller "unknown" — marketing_allowed=true` (36 over the whole run), which are not part of this change |

## What this does not establish

- Other Ultimate Member versions, UM extensions (social login, Stripe, registration over its REST API), or a site where UM
  registration is customised to insert users itself.
- A real CMP: consent was the ClickTrail plugin banner (S2); withdrawal after registration was not exercised.
- Provider acceptance of the `sign_up` event (the collector was a capture stub), or how a destination treats the aligned event
  ID; the collector received one event where two copies were sent, but the reason was not isolated.
- Simultaneous registration of one user (the claim is covered by PHPUnit with doubles, not by concurrent requests here).
- Uninstall (`uninstall.php` purge-all block) and the Diagnostics purge button: their purge-all call is the S6 method; the
  entry points themselves were not run.
- Page caches and optimisers in front of the registration page (the integration reads no `$_POST` and renders no markup).
