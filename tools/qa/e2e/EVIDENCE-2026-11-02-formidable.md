# Runtime evidence for the Formidable Forms adapter (#169), 2026-11-02

What ran: the `tools/qa/e2e` harness (`adapters-ci.sh`, then `ci.sh`) on the `feat/formidable-adapter` branch, Formidable Forms
(free) installed from wordpress.org inside the pod and driven by Chromium. A local pod, not a real page cache or CDN; every claim
below is limited to what the harness observed.

## Environment

| | |
|---|---|
| WordPress | 7.1 (`wordpress:php8.3-apache`) |
| PHP | 8.3.33 |
| Database | MariaDB 12.3.3 |
| Formidable Forms (free) | 7.0 (pinned in `adapters-setup.sh`); Formidable Pro was not exercised |
| Other plugins | Contact Form 7 6.2.2, Fluent Forms 6.2.16, WPForms Lite 2.0.3.1 (as before) |
| Browser | Chromium headless, Playwright (Python), `navigator.webdriver` masked as in the base harness |
| Debug | `WP_DEBUG`, `WP_DEBUG_LOG` on; `debug.log` scanned: 0 PHP diagnostics from the plugin |

Form: one Formidable form with a text field, an email field (`required`), hidden fields keyed `ct_gclid`, `ct_fbclid`, `ct_ft_source`,
`ct_lt_source`, `ct_lt_medium`, `ct_attribution_selected_click_id`, `ct_ft_content`, `ct_lt_content`, AJAX submit on, and an email
action printing `[ct_gclid]`, `[ct_lt_source]` and `[ct_lt_content]`.

## Result

`adapters-ci.sh`: **372 PASS, 7 FINDING, 0 FAIL**. `ci.sh`: **15 ok, 0 failure(s)**. The FINDING lines are the six WPForms Lite
entry-meta limitations present before this change and, in this run, the Contact Form 7 concurrent-REST double submit
(`S6 double_submit parallel_rest`: 2 rows, 1 dispatch; it was `PASS` in an earlier run of the same code, so it is a race in the
Contact Form 7 best-effort dedupe, not related to Formidable).

| Scenario | Formidable Forms |
|---|---|
| S1 landing, `?utm_source=t1&utm_medium=cpc&gclid=G1`, submit right after load; also with browser injection off | PASS: the stored lead, the entry fields and the entry meta carry `gclid=G1`, `lt_source=t1`, `lt_medium=cpc`, selected click id `G1`; entry meta also carries `ct_event_id` and `ct_lead_id`; exactly one `form_submission` row |
| S2 returning visitor | PASS: lead, entry and entry meta hold `fbclid=NEW`, `lt_source=new_src`, `ft_source=old_src` |
| S3a render: with A's cookie, rendered `ct_*` inputs | PASS: all empty, none of A's values anywhere in the HTML |
| S3b submit: render filter forced on, A's snapshot (carries A's values) served to B whose cookie lacks `gclid` | PASS: entry, meta and lead hold B's values, nothing for the keys B lacks, no value of A anywhere |
| S3c consent required and not granted (no cookie, denied cookie), stale snapshot posted | PASS: no ClickTrail row, no dispatch, every `ct_*` blank in the entry and the meta |
| S4 consent accept / deny / ignore | PASS: accept stores `G5` in lead and entry; deny and ignore leave no row, no dispatch, no `ct_*` value, no attribution cookie |
| S9 `gclid` + `utm_source` + `utm_content` | PASS: lead, entry, entry meta carry `gclid=GU`, `lt_source=news`, `lt_content=banner-a`, `ft_content=banner-a`; the email notification body reads `GCLID: GU`, `SOURCE: news`, `CONTENT: banner-a` |

## Not covered

Formidable Pro; finishing a saved draft (Formidable updates the entry through `frm_pre_update_entry`, which the adapter does not hook);
front-end entry edits; repeater child entries; Formidable versions other than 7.0; erasure; a real page cache. The six-case runtime
manifest `tests/fixtures/form-runtime/v1/formidable.json` stays `runtime_unverified`, like the other adapters.
