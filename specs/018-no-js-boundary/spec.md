# Feature Specification: No-JavaScript attribution boundary (documentation)

**Feature Branch**: `Atroci/ct-wp-14-no-js-docs`

**Created**: 2026-11-14

**Status**: Implemented (docs only). Every statement was checked against `main` @ `26da3d7` (1.13.1) by reading code; nothing was run against a live site or page cache.

**Input**: GitHub issue [#233](https://github.com/vizuh/click-trail-handler/issues/233). Since #152 (1.11.3) form `ct_*` fields render empty and the browser fills them; the `attribution` cookie is written only by `assets/js/clicutcl-attribution.js`. A visitor with JavaScript disabled or blocked produces leads with no attribution, and this trade-off was not documented. Related: [../005-cache-safe-hidden-fields/spec.md](../005-cache-safe-hidden-fields/spec.md) (#152), [../009-itp-cookie-reissue/spec.md](../009-itp-cookie-reissue/spec.md).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A site owner learns the no-JS boundary before relying on attribution (Priority: P1)

An owner reads the readme, the README or the integration reference and learns that visitors without JavaScript get no attribution, why, and what the opt-in render filter does.

**Independent Test**: read the five documents named in FR-001 and compare each claim with the code paths in FR-002.

**Acceptance Scenarios**:

1. **Given** a visitor with JavaScript disabled or blocked and no earlier `attribution` cookie, **Then** the docs state the lead is stored without attribution and why (the browser script is the only writer; no PHP code reads UTMs or click IDs from the request URL).
2. **Given** a visitor whose cookie was written on an earlier visit with JavaScript, **Then** the docs state the submit-time rule still reads it.
3. **Given** the filter `clicutcl_render_server_attribution_values` returning `true`, **Then** the docs state it renders the values of an existing cookie into form HTML only, reads no URL parameter, writes no cookie, helps no first visit and no visitor without a cookie, and belongs on sites without a page cache.

## Requirements *(mandatory)*

- **FR-001**: State the boundary in `docs/guides/SECURITY-PRIVACY.md` (cached-HTML section), `docs/reference/INTEGRATIONS.md` (submit-time rule), `README.en.md` and `README.pt-BR.md` (what it does not do) and the `readme.txt` FAQ (one Q/A). No change to `changelog.txt`, the stable tag or any code.
- **FR-002**: Every statement MUST match code: `Attribution_Provider::get_payload()` (reads `$_COOKIE['attribution']`, legacy `ct_attribution`, only when `should_populate()` allows), `Abstract_Form_Adapter::get_render_payload()` and `render_server_values_enabled()` (the filter, default `false`, second argument the platform name), `Attribution_Cookie_Reissue` (echoes the request's own cookie on `POST /clicutcl/v2/cookie-refresh` only), and the absence of any PHP read of campaign parameters from the URL.
- **FR-003**: Do not promise a workaround. The plugin has no server-side capture fallback.

## Success Criteria *(mandatory)*

- **SC-001**: The five documents state the same facts without contradicting each other or the existing #152 text.
- **SC-002**: `git diff --stat` lists only the documents above and `specs/018-no-js-boundary/`.

## Assumptions

- A page cache is the reason for #152; sites with none may opt in to the render filter.
- "Visitor without JavaScript" includes script blockers and proxies that strip scripts, not only a browser setting.
