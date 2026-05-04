# ClickTrail — Gravity Forms Attribution Update
## Plan v1 · 2026-04-29

Adds full channel classification, merge tags, per-form controls, and developer hooks to the
Gravity Forms integration. All work is inside the existing `CLICUTCL` namespace, `ct_` field
prefix, and `clicutcl_` option prefix. No external dependencies added.

---

## Acceptance criteria (done when all pass)

1. A GF entry shows a human-readable channel label (e.g. "Google Ads", "ChatGPT") in entry
   detail, entry list column, and entry export — without the user adding any form fields.
2. `{clicutcl_channel}`, `{clicutcl_utm_source}`, and 7 other merge tags resolve correctly
   in GF notifications and confirmations.
3. Tracking can be disabled per-form via the form settings tab; the global default toggle works.
4. All 12 click IDs (including rdt_cid, pin_cid, snap_cid, mc_cid, mc_eid, dclid) are captured.
5. AI assistant and email platform referrers are labelled correctly.
6. A `clicutcl_gf_tracking_enabled` filter lets developers override tracking per form.
7. `phpcs` passes at the same standard as the existing codebase.

---

## Phase 1 — Channel classification (JS + PHP)

### 1a · Add missing click IDs

**File:** `includes/Core/class-attribution-provider.php`

Add to `CLICK_ID_FIELDS` constant:
```php
'rdt_cid',   // Reddit
'pin_cid',   // Pinterest
'snap_cid',  // Snapchat
'mc_cid',    // Mailchimp campaign
'mc_eid',    // Mailchimp email
'dclid',     // Display & Video 360
```

**File:** `assets/js/clicutcl-attribution.js`

Add to `CLICK_ID_KEYS` array:
```js
'rdt_cid', 'pin_cid', 'snap_cid', 'mc_cid', 'mc_eid', 'dclid'
```

Add corresponding entries to `CLICK_ID_ALIASES`.

Note on `fbclid`: already captured. Must NOT be used as a paid signal on its own — only
classify as "Facebook Ads" when a paid medium (`cpc`, `paid`) is also present or when
`fbclid` co-occurs with `utm_medium=paid_social`. Organic Facebook = referrer from facebook.com
without a paid medium.

### 1b · Add `channel` touch field (JS)

**File:** `assets/js/clicutcl-attribution.js`

Add `channel` to the touch object alongside the existing UTM fields. `channel` is the
resolved human-readable label; `source` remains the raw utm_source. Classification rules
(evaluated in order, first match wins):

**Search — paid/organic split:**
| Condition | Label |
|---|---|
| gclid, gbraid, or wbraid present | `Google Ads` |
| referrer matches `google.com` (any TLD) | `Google Organic` |
| referrer matches `gemini.google.com` | `Gemini` |
| msclkid present | `Microsoft Ads` |
| referrer matches `bing.com` | `Bing Organic` |
| referrer matches `yahoo.com` | `Yahoo` |
| referrer matches `duckduckgo.com` | `DuckDuckGo` |
| referrer matches `yandex.` | `Yandex` |

**Social — paid/organic split:**
| Condition | Label |
|---|---|
| li_fat_id present | `LinkedIn Ads` |
| twclid present | `X Ads` |
| rdt_cid present | `Reddit Ads` |
| ttclid present | `TikTok Ads` |
| pin_cid present | `Pinterest Ads` |
| snap_cid present | `Snapchat Ads` |
| fbclid + paid medium present | `Facebook Ads` |
| referrer matches `facebook.com` or `fb.com` | `Facebook Organic` |
| referrer matches `instagram.com` | `Instagram Organic` |
| referrer matches `linkedin.com` or `lnkd.in` | `LinkedIn Organic` |
| referrer matches `twitter.com`, `t.co`, or `x.com` | `X Organic` |
| referrer matches `reddit.com` | `Reddit Organic` |
| referrer matches `tiktok.com` | `TikTok Organic` |
| referrer matches `pinterest.com` | `Pinterest Organic` |
| referrer matches `snapchat.com` | `Snapchat Organic` |

**AI assistants (referrer-based):**
| Referrer domain | Label |
|---|---|
| `chatgpt.com`, `chat.openai.com` | `ChatGPT` |
| `perplexity.ai` | `Perplexity` |
| `copilot.microsoft.com`, `bing.com/chat` | `Microsoft Copilot` |
| `claude.ai` | `Claude` |
| `grok.com`, `x.com/i/grok` | `Grok` |
| `deepseek.com` | `DeepSeek` |

**Email platforms (click-ID-based):**
| Condition | Label |
|---|---|
| mc_cid or mc_eid present | `Mailchimp` |
| utm_source matches `hubspot` or `hs_cta` present | `HubSpot` |
| utm_source matches `pardot` or `pi_u` present | `Salesforce Pardot` |
| utm_source matches `constantcontact` | `Constant Contact` |

**Generic fallbacks:**
| Condition | Label |
|---|---|
| No referrer, no UTM, no click ID | `Direct` |
| None of the above match | `Unknown` |

Write `channel` into the first-touch object as `ft_channel` / `lt_channel` using the same
touch-field mechanism as `source`, `medium`, etc.

### 1c · Expose `channel` in Attribution_Provider (PHP)

**File:** `includes/Core/class-attribution-provider.php`

Add `'channel'` to `TOUCH_FIELDS`. This ensures `ft_channel` / `lt_channel` flow through
`get_field_mapping()`, `sanitize()`, and `get_payload()` automatically.

### 1d · sessionStorage fallback (JS)

**File:** `assets/js/clicutcl-attribution.js`

When writing the attribution cookie fails (cookies blocked or unavailable), fall back to
`sessionStorage` using the same JSON envelope. On read, check cookie first, fall back to
`sessionStorage`. (Check whether this is already implemented before adding.)

### Verification — Phase 1

- Visit site with `?gclid=test` → `ft_channel` in cookie = `"Google Ads"`
- Visit from chatgpt.com referrer → `ft_channel` = `"ChatGPT"`
- Visit with `?mc_cid=123` → `ft_channel` = `"Mailchimp"`, `mc_cid` stored
- Visit with `?fbclid=abc` only → `ft_channel` = `"Facebook Organic"` (not Ads)
- Block cookies in browser → attribution stored in sessionStorage, readable on next page

---

## Phase 2 — GF entry meta: channel field + missing fields

**File:** `includes/integrations/forms/class-gravity-forms-adapter.php`

### 2a · Update `register_entry_meta()`

The existing method registers all keys from `Attribution_Provider::get_field_mapping()` as GF
entry meta. Since Phase 1 adds `channel` to TOUCH_FIELDS, `ft_channel` and `lt_channel` will
appear automatically in `get_field_mapping()`.

Verify the label for the channel fields is user-friendly:
```php
// Current:
$label = 'ClickTrail: ' . ucwords( str_replace( '_', ' ', $key ) );
// Result for ft_channel: "ClickTrail: Ft Channel" — acceptable; no change needed.
```

### 2b · Update `on_submission()` — server-side channel fallback

If `ft_channel` is empty in the payload at submission time (e.g. JS was blocked), compute a
server-side best-effort channel from `ft_source`, `ft_medium`, and the presence of click IDs
already in the payload:

Add private method `resolve_channel_fallback( array $payload ): string` to
`Gravity_Forms_Adapter`. Apply only when `ft_channel` is absent. Store result under
`ct_ft_channel` entry meta.

Also: guarantee that `ct_ft_channel` defaults to `"Unknown"` (never empty) when the entry is
saved, matching the behavior of the existing `ft_source` field.

### 2c · Entry edit safety

Tracking fields excluded from the entry edit screen so GF never overwrites meta with empty POST
data. Add to `register_hooks()`:

```php
add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'exclude_fields_from_edit' ), 10, 3 );
```

Add `gform_after_update_entry` safety-net to restore `ct_*` meta values cleared during an edit:

```php
add_action( 'gform_after_update_entry', array( $this, 'restore_tracking_meta_after_edit' ), 10, 3 );
```

### Verification — Phase 2

- Submit a GF form with `?gclid=abc` in URL → entry detail shows "ClickTrail: Ft Channel" = "Google Ads"
- Edit the entry → tracking values unchanged after save
- Export entries → `ct_ft_channel`, `ct_ft_source`, `ct_ft_medium` etc. present in CSV

---

## Phase 3 — GF merge tags

**File:** `includes/integrations/forms/class-gravity-forms-adapter.php`

### 3a · Register merge tags

Add to `register_hooks()`:
```php
add_filter( 'gform_custom_merge_tags', array( $this, 'register_merge_tags' ), 10, 4 );
add_filter( 'gform_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 7 );
```

Add method `register_merge_tags( $merge_tags, $form_id, $fields, $element_id )`:
- Return `$merge_tags` unchanged if tracking disabled for `$form_id`
- Otherwise append the merge tag definitions below

**Merge tag map** (all prefixed `clicutcl_`):

| Merge tag | Resolves from |
|---|---|
| `{clicutcl_channel}` | `ct_ft_channel` entry meta |
| `{clicutcl_referrer}` | `ct_ft_referrer` entry meta |
| `{clicutcl_utm_source}` | `ct_ft_source` entry meta |
| `{clicutcl_utm_medium}` | `ct_ft_medium` entry meta |
| `{clicutcl_utm_campaign}` | `ct_ft_campaign` entry meta |
| `{clicutcl_utm_term}` | `ct_ft_term` entry meta |
| `{clicutcl_utm_content}` | `ct_ft_content` entry meta |
| `{clicutcl_utm_id}` | `ct_ft_utm_id` entry meta |
| `{clicutcl_click_id}` | First non-empty of: ct_gclid, ct_msclkid, ct_ttclid, ct_li_fat_id, ct_rdt_cid, ct_pin_cid, ct_snap_cid, ct_mc_cid |

Add method `replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format )`:
- Walk the merge tag map
- For each `{clicutcl_*}` tag found in `$text`, read the value from `gform_get_meta( $entry['id'], $meta_key )`
- Apply `esc_html` / `urlencode` / `nl2br` per GF convention
- Default to empty string when no value; expose via `clicutcl_gf_merge_tag_default_value` filter

### 3b · Developer filters

```php
// Override the resolved merge tag value before output
apply_filters( 'clicutcl_gf_merge_tag_value', $value, $tag, $entry, $form );

// Override the formatted merge tag value (after escaping)
apply_filters( 'clicutcl_gf_merge_tag_formatted_value', $formatted, $tag, $entry, $form, $url_encode, $esc_html, $format );

// Override the fallback when a tag has no value
apply_filters( 'clicutcl_gf_merge_tag_default_value', '', $tag );
```

### Verification — Phase 3

- Create a GF notification with `{clicutcl_channel}` in the body
- Submit form from a Google Ads click (`?gclid=test`) → notification body contains "Google Ads"
- Merge tags visible in GF merge tag picker (modal) when tracking enabled for form

---

## Phase 4 — Per-form settings

**File:** `includes/integrations/forms/class-gravity-forms-adapter.php`

### 4a · Global default option

**File:** `includes/settings/class-attribution-settings.php`

Add option key `gf_tracking_default_enabled` (bool, default `true`). Expose in the existing
admin settings page under the Attribution tab (or a new Gravity Forms sub-tab).

### 4b · Per-form settings tab

Add to `register_hooks()`:
```php
add_filter( 'gform_form_settings', array( $this, 'add_form_settings_tab' ), 10, 2 );
add_filter( 'gform_pre_form_settings_save', array( $this, 'save_form_settings' ), 10, 2 );
```

`add_form_settings_tab( $settings, $form )`:
- Add a "ClickTrail" section with a single toggle: "Enable attribution tracking for this form"
- Default = value of `gf_tracking_default_enabled`
- Render using GF's native settings API (`GFFormSettings`)

`save_form_settings( $settings, $form_id )`:
- Read the checkbox value from `$settings`
- Persist with `gform_update_meta( $form_id, 'clicutcl_tracking_enabled', $value )`

### 4c · `is_tracking_enabled_for_form( $form_id )` helper

Private method in `Gravity_Forms_Adapter`. Logic:
```
1. Read gform_get_meta( $form_id, 'clicutcl_tracking_enabled' )
2. If null (never set), fall back to global default option
3. Apply clicutcl_gf_tracking_enabled filter
```

Replace all inline tracking checks in the adapter with this helper.

### 4d · Developer filter

```php
apply_filters( 'clicutcl_gf_tracking_enabled', $enabled, $form_id, $form );
// Params: bool $enabled, int $form_id, array|null $form
```

### Verification — Phase 4

- Disable tracking on form #5 via form settings tab
- Submit form #5 → no `ct_*` entry meta written
- `clicutcl_gf_tracking_enabled` filter returning `false` for form ID suppresses all tracking

---

## Phase 5 — Entry list columns

The existing `register_entry_meta()` already sets `is_default_column: false`, which makes
the columns available (but off by default) in the GF entry list column selector.

### 5a · Confirm column selectability

Manually verify in a test environment that `ct_ft_channel`, `ct_ft_source`, and `ct_ft_medium`
appear in the "Choose Columns" dropdown of the entries list. If they do — no code change needed,
just document it.

If they do not appear, the fix is to ensure the meta keys registered in `register_entry_meta()`
match exactly what is stored by `gform_add_meta()` in `on_submission()`.

### 5b · Canonical key consistency check

Ensure the key stored via `gform_add_meta( $entry_id, $meta_key, $value )` and the key
registered in `register_entry_meta()` are identical. Both use `$this->get_field_name( $key )`
which prepends `ct_`. Confirm no legacy/numeric key drift.

### Verification — Phase 5

- Submit a form → open Entries list → click column selector → "ClickTrail: Ft Channel" is
  available and, when enabled, shows the correct label for that submission

---

## Phase 6 — Script minification protection + admin QA mode

### 6a · Script minification protection

**File:** `includes/integrations/forms/class-gravity-forms-adapter.php` or the asset enqueueing
code in `includes/class-clicutcl-core.php`

When enqueueing `clicutcl-attribution.js` and `clicutcl-consent.js` (or their built equivalents),
add data attributes used by common cache/optimization plugins:

```php
add_filter( 'script_loader_tag', array( $this, 'add_minification_exclusion_attrs' ), 10, 2 );
```

Attributes to add on matching script handles:
- `data-no-optimize="1"` — Autoptimize
- `data-cfasync="false"` — Cloudflare Rocket Loader
- `data-wprocket-exclude` — WP Rocket
- `data-litespeed-no-optimize` — LiteSpeed Cache

Add a settings notice (admin only) listing the two JS paths users should exclude manually if
automatic exclusion fails.

### 6b · Admin user QA mode

**File:** `assets/js/clicutcl-attribution.js`

When `CONFIG.adminQaMode` is `true` (set server-side when current user `manage_options`),
use `sessionStorage` only — no persistent cookie written. This prevents admin browsing from
polluting attribution data.

**File:** `includes/class-clicutcl-core.php` (or wherever `clicutcl_config` is localised)

Add `adminQaMode: current_user_can('manage_options')` to the localised config.

### Verification — Phase 6

- Log in as admin, visit site → no `ct_attribution` cookie set; `sessionStorage` used instead
- Log out, visit site → persistent cookie set as normal
- WP Rocket: confirm `clicutcl-attribution` script handle is excluded from deferral

---

## Phase 7 — Developer hooks surface (GF-specific)

All filters documented in `docs/reference/HOOKS-REFERENCE.md` under a new `## Gravity Forms`
section.

**Summary of all new hooks introduced in this update:**

| Hook | Type | Phase |
|---|---|---|
| `clicutcl_gf_tracking_enabled` | filter | 4 |
| `clicutcl_gf_merge_tag_value` | filter | 3 |
| `clicutcl_gf_merge_tag_formatted_value` | filter | 3 |
| `clicutcl_gf_merge_tag_default_value` | filter | 3 |
| `clicutcl_gf_channel_label` | filter | 1b (optional) — override computed channel for a touch |

---

## File change summary

| File | Change type |
|---|---|
| `assets/js/clicutcl-attribution.js` | Extend: missing click IDs, channel classification, sessionStorage fallback, admin QA mode |
| `includes/Core/class-attribution-provider.php` | Extend: add `channel` to TOUCH_FIELDS, add missing IDs to CLICK_ID_FIELDS |
| `includes/integrations/forms/class-gravity-forms-adapter.php` | Extend: server-side channel fallback, entry edit safety, merge tags, per-form toggle, minification attrs |
| `includes/settings/class-attribution-settings.php` | Extend: add `gf_tracking_default_enabled` option |
| `docs/reference/HOOKS-REFERENCE.md` | Document all new GF hooks |

No new files. No schema changes. No new composer dependencies.

---

## Sequencing for Codex

Phases are ordered by dependency. Run in sequence:

```
Phase 1 (JS + Attribution_Provider)
  → Phase 2 (GF entry meta uses Phase 1 fields)
    → Phase 3 (merge tags read from Phase 2 meta)
      → Phase 4 (per-form toggle gates Phases 2 + 3)
        → Phase 5 (column verification, no code if Phase 2 is correct)
          → Phase 6 (housekeeping, independent)
            → Phase 7 (docs, last)
```

Each phase has its own verification step. Run `phpcs` after each phase; do not accumulate
violations across phases.
