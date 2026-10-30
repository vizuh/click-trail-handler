# Contract: JS rule module (`window.ClickTrailRules`)

Defined at the top of `assets/js/clicutcl-attribution.js`, in its own IIFE before the runtime IIFE. The runtime reads every rule from it; it keeps no second copy (FR-003). Chosen over a separate script file and over a test-only hook in research R1.

## Shape

```js
window.ClickTrailRules = Object.freeze({
  version: 1,

  // Constants (frozen arrays/objects)
  TOUCH_QUERY_FIELD_MAP,     // utm_source -> source, ...
  CLICK_ID_KEYS,             // 16 canonical click IDs
  PAID_MEDIUMS,

  // Sanitization
  sanitizeKey(rawKey) -> string,
  sanitizeValue(value, maxLen) -> string,
  canonicalizeAttributionKey(rawKey) -> string,
  sanitizeAttributionData(input) -> object,

  // Click IDs
  withCanonicalClickIds(raw) -> object,

  // Touch
  mapQueryFields(params) -> object,

  // Referrers and channel
  isIgnoredReferrer(referrer, ignoredHosts, pageUrl) -> boolean,
  resolveChannelLabel(params, referrer, pageUrl) -> string,
});
```

Exact membership is settled during implementation; the rule is that every function a fixture (data-model section 2) names is a member, and nothing that touches state is.

## Rules

1. **Pure.** A member function reads only its arguments and the module's frozen constants. It does not read `window.location`, `document`, `clicutcl_config`, cookies or storage. Values the runtime used to read implicitly (`window.location.href` as the URL base, `CONFIG.ignoredReferrerHosts`) become explicit arguments, and the runtime passes them.
2. **No data.** The module holds no visitor, session, consent or attribution values. Exposing it reveals nothing that is not already in the page source, which is why it may be a production global (D3).
3. **Frozen.** `Object.freeze` on the module and on each constant. A page script cannot replace a rule the runtime uses.
4. **Defined once.** If `window.ClickTrailRules` already exists with the same `version`, the definition is skipped (double enqueue by a page builder). A different `version` is overwritten and, with debug on, logged.
5. **Load order.** The runtime IIFE starts after the module in the same file. No new script handle, no `wp_register_script` dependency change, no change to `Gf_Minification_Protector::CT_HANDLES` or the NitroPack compat list.
6. **Behavior-preserving extraction.** Moving a function into the module must not change its output. The existing `tools/qa/smoke.js`, `payment-referrer.test.js`, `cmp-transition.test.js` and `hubspot-form-capture.test.js` pass unchanged before any fixture-driven fix is made.
7. **Not a public API.** Documented in `CODE-MAP.md` as internal. It is not listed in `HOOKS-REFERENCE.md`; sites must not depend on it, and its `version` may change in any release.

## Test-hook marker (US4)

The string `__clicutclTest` is reserved. No file under `assets/js/` may contain it, so no conditional test hook can ship. `tools/release/make-zip.js` `validateManifest()` fails when any `.js` entry in the built zip contains it, or when the zip has an entry under `tests/`, `tools/` or `specs/`.
