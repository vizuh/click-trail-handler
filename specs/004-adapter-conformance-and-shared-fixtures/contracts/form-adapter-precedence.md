# Contract: form adapter attribution precedence (D1)

Every class implementing `Form_Adapter_Interface` resolves submission attribution through one shared method. Adapters differ only in how they collect posted `ct_*` values from their host plugin.

## Shared API (production)

On `Attribution_Provider`:

```php
/** True when the sanitized payload has at least one attribution key (not only identity keys). */
public static function is_usable_payload( array $sanitized ): bool;

/** visitor_id and session_id from their own cookies, or []. Consent-gated like get_payload(). */
public static function get_identity_payload(): array;
```

On `Abstract_Form_Adapter`:

```php
/**
 * @param array<string,mixed> $posted_ct Submitted values keyed by field name (e.g. 'ct_lt_source').
 *                                       Non-ct_ keys are ignored.
 * @return array{source: 'cookie'|'posted'|'none', attribution: array<string,string|int>}
 */
protected function resolve_submission_attribution( array $posted_ct ): array;
```

Each adapter supplies `$posted_ct` from its host plugin's submission data (research R3) and persists `attribution` when `source` is not `none`.

## Resolution order

1. **Consent gate.** If `! should_populate()`, or `Consent::is_required() && ! Consent::marketing_allowed()`, return `none`. Nothing is read.
2. **Cookie.** `$cookie = get_attribution_payload()`. If `is_usable_payload( $cookie )`, return `cookie` with `$cookie` (which already carries `visitor_id` and `session_id`).
3. **Posted.** Keep entries of `$posted_ct` whose key is `ct_` + a key in `get_field_mapping()`, strip the prefix, and pass the result through `Attribution_Provider::sanitize()`. If `is_usable_payload()`, return `posted` with that record plus `get_identity_payload()`.
4. **None.** Return `none`.

Sources are never merged key by key.

## Per-adapter obligations

| Adapter | Posted `ct_*` source | Change from today |
|---|---|---|
| Contact Form 7 | `WPCF7_Submission::get_posted_data()` | Drop the `array_merge` per-key fill; use the resolver. |
| Elementor Forms | record fields from `extract_record_fields()` | Cookie now wins over posted; posted values get full `sanitize()` instead of `sanitize_text_field()`. |
| Fluent Forms | `$formData['ct_*']` at `fluentform/submission_inserted` | Add posted fallback, and add ClickTrail's `ct_*` names via `fluentform/white_listed_fields`, without which Fluent drops them before the hook. |
| Gravity Forms | entry values for fields whose `inputName` (or `inputs[].name`) starts with `ct_` | Add posted fallback in `on_submission`. `refresh_submitted_fields()` writes the whole cookie record: inputs whose key the cookie lacks are set to `''` instead of keeping the posted value, and nothing is written when the cookie is not usable. |
| Ninja Forms | `$data['fields_by_key']['ct_*']['value']` at `ninja_forms_after_submission` | Add posted fallback. |
| WPForms | `$fields[<id>]['value']` for Hidden fields whose label (`$fields[<id>]['name']`) is a `ct_*` name (R3, decided 2026-10-30) | Add posted fallback. Persistence in Lite is `log_submission()` only (`$entry_id` is 0). |

Exact host data shapes per adapter: research R3.

## Invariants the conformance suite asserts

- `source = none` ⇒ no persisted attribution and no `log_submission()` row.
- `source = cookie` ⇒ persisted record equals the cookie record (no posted key present that the cookie lacks).
- `source = posted` ⇒ every persisted value equals `Attribution_Provider::sanitize()` of the posted value.
- Consent failure ⇒ `none`, regardless of cookie or posted contents.
