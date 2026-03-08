# Code Quality Notes

- **Audience**: maintainers, reviewers, and cleanup-focused contributors
- **Canonical for**: current maintenance posture, known dead paths, and cleanup hotspots
- **Update when**: legacy paths are removed, major cleanup lands, or quality risks materially change
- **Last verified against version**: `1.3.5`

This document summarizes the current quality posture of the repository and the main maintenance concerns worth watching.

## Current Strengths

- Settings sanitization is schema-based in the core option stores.
- Advanced tracking settings preserve hidden secrets safely for admin clients.
- Server-side delivery is separated into dispatcher, queue, adapters, and diagnostics concerns.
- Browser events, webhook intake, lifecycle updates, and purchase dispatch converge on one shared delivery path.
- The admin experience now presents one primary settings identity instead of exposing internal "v2" terminology to users.

## Current Maintenance Hotspots

## 1. Legacy admin leftovers

There are still legacy admin renderer and asset surfaces in the repository that are not part of the active settings flow.

Examples:

- old settings renderer methods in `includes/admin/class-admin.php`
- `assets/js/admin-tracking-v2.js`
- `assets/js/admin-settings.js`

These should be treated as cleanup candidates, not as the source of truth for current behavior.

## 2. Internal naming that still reflects older architecture

The option key `clicutcl_tracking_v2` remains active because it is part of the current storage contract.

This is intentional for compatibility, but future contributors should avoid reintroducing that label into the public UI copy.

## 3. Documentation drift risk

The codebase has several distinct domains:

- browser capture
- consent
- canonical tracking
- queue and delivery
- WordPress admin

Because of that, admin or runtime refactors can easily leave docs stale unless the docs are updated in the same pass.

## 4. Test coverage gap

The repository currently does not expose an automated test suite in `package.json`, and no local PHP or JS test harness is part of the standard repo workflow.

That makes these areas particularly sensitive to regressions:

- grouped admin save/load mapping
- form adapter behavior
- REST auth edge cases
- queue retry semantics

## Suggested Review Checklist for Future Changes

- Does the change affect the capability-based admin IA?
- Does it alter any of the five main option stores?
- Does it change REST auth or token behavior?
- Does it impact queue retry or diagnostics retention?
- Does it affect form adapters or WooCommerce attribution persistence?
- Do the GitHub docs and WordPress `readme.txt` still describe the current state correctly?
