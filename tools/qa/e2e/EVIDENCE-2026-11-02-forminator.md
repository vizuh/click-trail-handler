# Runtime evidence for the Forminator adapter (#170), 2026-11-02

What ran: the `tools/qa/e2e` throwaway-WordPress harness (`adapters-ci.sh`) with the `Forminator_Adapter` from branch
`feat/forminator-adapter` (on top of `5390c84`), free Forminator installed from wordpress.org inside the pod, driven by
Chromium. It is a local pod, not a real cache, CMP or CDN; every claim is limited to what the harness observed.

| | |
|---|---|
| WordPress | 7.1 (image `wordpress:php8.3-apache`) |
| PHP | 8.3.33 |
| Database | MariaDB 12.3.3 |
| Forminator (free) | 1.58.4 (pinned by `adapters-setup.sh`) |
| Browser | Chromium 147.0.7727.15 headless, Playwright (Python), `navigator.webdriver` masked as in the base harness |
| Debug | `WP_DEBUG`, `WP_DEBUG_LOG` on; `debug.log` scanned after the run |

Forms (`fixtures-forminator.php`, all AJAX mode, hidden fields `hidden-1..6` for `gclid`, `fbclid`, `ft_source`, `lt_source`,
`lt_medium`, `attribution_selected_click_id`, notification `GCLID: {hidden-1}<br/>SOURCE: {hidden-4}<br/>{all_fields}`):

| Provider | Marker | Forminator Default value |
|---|---|---|
| `forminator` | custom CSS class `ct_<key>` | Custom value `forminator-static` |
| `forminator_label` | label exactly `ct_<key>` | User IP Address |
| `forminator_custom` | Default value Custom value set to exactly `ct_<key>` | Custom value |

## Results

`adapters-ci.sh`, full run, exit 0: **456 PASS, 6 FINDING, 0 FAIL** (145 judged lines name Forminator: 140 PASS, 5 INFO, no
FAIL or FINDING). The six FINDING lines are the existing WPForms Lite "no entry meta API" notes. `ci.sh` (round trip): 15 `ok`,
`0 failure(s)`.

| # | Scenario | Result for the three Forminator forms |
|---|---|---|
| 1 | Landing `?utm_source=t1&utm_medium=cpc&gclid=G1`, submit by AJAX: one `form_submission` row with `platform=forminator`, the stored entry fields `hidden-1/3/4/5/6` hold `G1`, `t1`, `t1`, `cpc`, `G1` (not `forminator-static`, not the literal `ct_` name), the entry meta carries the same `ct_*` keys plus `ct_event_id` and the derived `ct_lead_id`, the notification mail reads `GCLID: G1` and `SOURCE: t1` | PASS |
| 1b | Same with browser injection off (`enable_js_injection=0`): the rendered Hidden field is `''` at submit, so the stored value is the server's submit-time fill | PASS |
| 2 | Returning visitor (`?gclid=OLD...`, then `?fbclid=NEW&utm_source=new_src`): entry, entry meta, lead and mail hold the newest touch | PASS |
| 3a | Render layer: with visitor A's cookie (`gclid=AAA`) the served HTML holds no `ct_*` value | PASS (class form) |
| 3b | Submit layer, render filter forced on so A's snapshot carries A's values in the Hidden fields (they were in B's DOM); B (cookie lacks `gclid`, `fbclid=BFB`) submits: entry, lead and entry meta hold B's values, nothing for the keys B lacks, no value of A in the mail | PASS (class form) |
| 3c | Consent required and not granted (no cookie; denied cookie), stale snapshot posted: no ClickTrail row, no dispatch, every `ct_*` field blank, one notification mail with no value of A | PASS (class form) |
| 4 | Consent on: accept then submit (`gclid=G5` in entry and lead); deny and ignore: no row, no dispatch, entry holds no attribution value, mail reads `GCLID: N/A`, no attribution cookie | PASS |
| 10 | `debug.log`: no PHP notice, warning or deprecation from the plugin, none naming a Forminator hook the adapter registers | PASS |

Not run against Forminator here: non-AJAX forms, Forminator Pro-only fields and add-ons, Stripe/PayPal pending entries,
Forminator "do not store submissions" (the code path is unit-tested only), draft and abandoned-form entries (skipped in code,
unit-tested only), a real CMP, a real page cache or CDN, erasure/export, other Forminator versions.

Reproduce: `flock /tmp/ct-e2e.lock tools/qa/e2e/adapters-ci.sh`, or a subset against a pod from `adapters-setup.sh`:
`python3 tools/qa/e2e/adapters.py <out-dir> landing returning cache_leak consent`, then `verify_adapters.py <out-dir>`.
