# Data Model: Adapter Conformance and Shared Attribution Fixtures

Test-side entities plus the two production additions (the JS rule module and the D1 resolver). Nothing here is stored in the database or a cookie.

## 1. Rule fixture (`tests/fixtures/attribution-rules/<rule>.json`)

Schema: [contracts/rule-fixture.schema.json](contracts/rule-fixture.schema.json).

| Field | Type | Rule |
|---|---|---|
| `rule` | string | Equals the file name without `.json`. |
| `version` | int ≥ 1 | Bumped when the rule changes on purpose. The changelog entry names the file. |
| `notes` | string | Documents representation differences between implementations (D2). Required, may be short. |
| `implementations` | string[] | Implementation IDs (section 3) this fixture runs against. Every listed ID must exist in both registries' union; an unknown ID fails the loader. |
| `cases[]` | object | See below. Case `id` values are unique within the file. |
| `exceptions[]` | object | See below. May be empty. |

**Case**

| Field | Type | Rule |
|---|---|---|
| `id` | string, kebab-case | Stable; renaming a case is a fixture change. |
| `input` | object | Used when every implementation takes the same shape. |
| `raw` | object | Query params (`params`) plus `referrer` and `page_url` strings: what the browser sees. |
| `flattened` | object | `ft_*` / `lt_*` / top-level keys: what the cookie and PHP payloads hold. |
| `nested` | object | `{ first_touch, last_touch, ... }`: what WooCommerce order attribution holds. |
| `expected` | any | One result for all implementations. |

Exactly one of `input`, or one or more of `raw` / `flattened` / `nested`, must be present. Each implementation declares which shape it reads (section 3); a case lacking that shape fails the loader for that implementation rather than being skipped.

**Exception**

| Field | Type | Rule |
|---|---|---|
| `case` | string | An existing case `id`. |
| `implementation` | string | An implementation ID. |
| `expected` | any | What this implementation returns instead. Asserted, so the exception itself is pinned. |
| `reason` | string, non-empty | Why the difference is intended. |
| `regression_test` | string, non-empty | `path::method` of a test outside the fixture suite that pins the behavior. The loader checks the file exists. |

## 2. Rule set (initial fixtures)

| File | Implementations | Input shapes |
|---|---|---|
| `click-id-precedence.json` | `js.withCanonicalClickIds`, `php.attribution_provider.sanitize`, `php.eventv2.normalize_click_ids`, `php.token.normalize_click_ids`, `php.woocommerce.flatten` | `flattened`; `nested` for Woo |
| `touch-mapping.json` | `js.mapQueryFields`, `php.attribution_provider.field_mapping` | `raw` (JS), `input` key list (PHP) |
| `ignored-referrers.json` | `js.isIgnoredReferrer`, `php.attribution_provider.ignored_hosts` | `input` `{ referrer, page_url }` |
| `channel-label.json` | `js.resolveChannelLabel`, `php.gf_channel_resolver.resolve` | `raw` (JS), `flattened` (PHP) |
| `value-sanitization.json` | `js.sanitizeAttributionData`, `php.attribution_provider.sanitize` | `input` |

Known divergences each fixture will surface on first run are listed in research.md R4; each must end as a code fix or an `exceptions[]` entry before the suite goes green.

## 3. Implementation registry

One map per language from implementation ID to a callable that takes the case input in its declared shape and returns a value comparable to `expected`.

- PHP: `tests/helpers/attribution-rules/class-rule-implementations.php`. Private normalizers (`EventV2::normalize_click_ids`, the token trait's `normalize_click_ids_for_token`, `WooCommerce::flatten_attribution_for_event`) are invoked through `ReflectionMethod::setAccessible(true)`. No production visibility change.
- JS: `tools/qa/attribution-rules/implementations.js`, calling `window.ClickTrailRules` after loading the real `clicutcl-attribution.js` in a `vm` context.

An implementation ID prefixed `js.` belongs to the JS registry, `php.` to the PHP one. Each suite runs only its own prefix and fails if a fixture lists an ID of its prefix that its registry lacks.

## 4. JS rule module (`window.ClickTrailRules`)

Production object, defined at the top of `assets/js/clicutcl-attribution.js` before the runtime IIFE, and used by that runtime. API: [contracts/js-rule-module.md](contracts/js-rule-module.md).

- Holds pure functions and frozen constants only. No visitor data, no storage access, no DOM access, no reads of `window.location` or `clicutcl_config` inside a function: those are passed as arguments.
- `Object.freeze`d, so the runtime and the tests see the same functions and a page script cannot swap them.
- `version` integer, bumped with any rule change.

## 5. Submission attribution resolver (D1)

Production addition on `Abstract_Form_Adapter`. Contract: [contracts/form-adapter-precedence.md](contracts/form-adapter-precedence.md).

States for one submission, evaluated in order:

```text
consent gate fails            -> NONE      (nothing persisted, no event row)
usable cookie                 -> COOKIE    (cookie record, plus visitor_id/session_id)
usable posted ct_* values     -> POSTED    (sanitized posted record, plus visitor_id/session_id if cookies exist)
otherwise                     -> NONE
```

`usable` = after `Attribution_Provider::sanitize()`, at least one key from `get_field_mapping()` other than `visitor_id`, `session_id`, `session_count`, `session_number`.

The resolved source (`cookie` / `posted`) is returned with the record so drivers can assert it; it is not persisted.

## 6. Adapter driver (`tests/helpers/form-drivers/`)

Contract: [contracts/adapter-driver.md](contracts/adapter-driver.md).

| Member | Rule |
|---|---|
| `adapter_class(): string` | The production class it drives. |
| `submit( array $cookies, array $posted_ct, array $options ): void` | Sets `$_COOKIE`, builds the host plugin's hook arguments with `$posted_ct` placed where that plugin carries submitted values (research R3), and fires the adapter's submit hook(s) in host order. `$options` carries `form_id` and `tracking_enabled`. |
| `persisted(): array` | What the adapter wrote, read from the stub storage. `[]` if nothing. |
| `event_rows(): array` | Rows `log_submission()` would have inserted. |
| `reset(): void` | Clears stub storage and globals it owns. |

## 7. Driver registry

`tests/helpers/form-drivers/registry.php` returns `array<adapter_class, driver_class>`. Compared by `FormAdapterRegistryTest` against `Form_Integration_Manager::ADAPTER_CLASSES` and against the classes implementing `Form_Adapter_Interface` found by scanning `includes/integrations/forms/class-*-adapter.php`.

## 8. Live suite report row

Written by `tools/qa/e2e/` as JSON lines and printed as a table:

| Field | Values |
|---|---|
| `adapter` | Production class short name. All six always appear. |
| `scenario` | `landing-submit`, `navigate-then-submit`. |
| `status` | `passed`, `failed`, `not_run`. |
| `reason` | Required when `not_run` (for example `licence required`). |

The job fails on any `failed`. `not_run` never counts as passing and is listed in the job summary.
