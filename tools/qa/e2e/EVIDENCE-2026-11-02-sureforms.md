# Runtime evidence for the SureForms adapter (#172, 2026-11-02)

What ran: `tools/qa/e2e/adapters-ci.sh` (the form-adapter harness, see `README.md`) against the `feat/sureforms-adapter`
checkout (`bbc3a97`, plugin code `1.12.0` plus the adapter), with SureForms (free) installed from wordpress.org inside the
pod next to Contact Form 7, Fluent Forms and WPForms Lite. Local pod, Chromium, no real page cache; every claim is limited to
what the harness observed.

## Environment

| | |
|---|---|
| WordPress | 7.1 (`wordpress:php8.3-apache`, `sha256:74dc0652…6f18b`) |
| PHP | 8.3.33 |
| Database | MariaDB 12.3.3 |
| SureForms (free) | 2.13.0 (pinned in `adapters-setup.sh`) |
| Other plugins | Contact Form 7 6.2.2, Fluent Forms 6.2.16, WPForms Lite 2.0.3.1 (unchanged) |
| Debug | `WP_DEBUG`, `WP_DEBUG_LOG` on; `debug.log` scanned |

Full run: **367 PASS, 6 FINDING, 0 FAIL**, exit 0. The 6 FINDING lines are the existing WPForms Lite "no entry meta API" notes.
SureForms contributed 51 PASS and 0 FINDING / FAIL.

## Fixture

One published `sureforms_form` post (block markup, `tools/qa/e2e/fixtures.php`): a Text field `Name`, an Email field, and six
Text fields whose labels are `ct_gclid`, `ct_fbclid`, `ct_ft_source`, `ct_lt_source`, `ct_lt_medium`,
`ct_attribution_selected_click_id` (slugs `ct-<key>`). Not hidden by CSS (the harness fills only Name and Email). Rendered
keys look like `srfm-input-c7e2a000-lbl-Y3RfZ2NsaWQ-ct-gclid`; the browser Injector cannot match them, so no value is ever
written by the browser. The provider record is the entry row in `wp_srfm_entries` (`form_data` and `extras`); the
notification mail is the `pre_wp_mail` capture of the E2E mu-plugin.

## Results (SureForms)

| # | Scenario | Result |
|---|---|---|
| S1 landing, S1 server-only landing | Land with `?utm_source=t1&utm_medium=cpc&gclid=G1`, submit | PASS: one `form_submission` row (`platform` `sureforms`), the entry's `ct_gclid`, `ct_lt_source`, `ct_lt_medium`, `ct_attribution_selected_click_id` hold `G1` / `t1` / `cpc` / `G1` (written at submit from the cookie sent with the REST request), the entry `extras` carry the same plus `ct_event_id` / `ct_lead_id` equal to the stored lead's IDs, SureForms' own `is_after_submission_process_triggered` flag survives next to them, the notification mail carries the `ct_*` label and value |
| S2 returning | `?gclid=OLD&utm_source=old_src` then `?fbclid=NEW&utm_source=new_src` | PASS: entry and `extras` hold `fbclid=NEW`, `lt_source=new_src`, `ft_source=old_src`, selected click `NEW` |
| S3 cache | Render layer: with A's cookie every `ct_*` input is empty; submit layer: A's values planted in the posted inputs (the render filter has no effect on SureForms), B's cookie lacks `gclid` | PASS: entry, `extras` and lead hold B's values and nothing for the keys B lacks, no value of A anywhere; consent required and not granted (no cookie, denied cookie) with A's values posted: no ClickTrail row, no dispatch, every `ct_*` blank in the entry |
| S4 consent | Accept, deny, ignore on the banner | PASS: accept stores `gclid=G5` in lead and entry (93 ms after the click); deny and ignore leave no row, no dispatch, blank `ct_*` fields, no attribution cookie, `extras` holding only SureForms' own flag |
| debug.log | `WP_DEBUG_LOG` | PASS: no PHP notice, warning or deprecation from the plugin (`0 unexplained`), no `srfm_*` hook deprecation |

## Not proven

- The Pro Hidden field and any SureForms version other than 2.13.0 (hook names were checked against the 1.12.0, 2.0.0,
  2.12.8 and 2.12.9 sources, not run).
- A real page cache or CDN; the cache scenario plants the stale values in the posted inputs.
- Entry display in the SureForms admin UI (the `extras` column is read from the database).
- Erasure; third-party retention of the mail.
- A first exploratory run of the harness (same pod, before this full run) raised one Playwright timeout waiting for a WPForms
  confirmation in `landing`; it did not recur in the full run above and was not investigated.
