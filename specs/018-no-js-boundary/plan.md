# Implementation Plan: No-JavaScript attribution boundary (documentation)

**Branch**: `Atroci/ct-wp-14-no-js-docs` | **Date**: 2026-11-14 | **Spec**: [spec.md](spec.md) | **Issue**: [#233](https://github.com/vizuh/click-trail-handler/issues/233)

## Summary

Docs only. Add one precise passage per document: the browser script is the only writer of the `attribution` cookie, so a visitor with no JavaScript and no earlier cookie gives a lead without attribution; the reason is cache safety (#152); the opt-in render filter renders an existing cookie's values and captures nothing.

## Technical Context

**Language/Version**: Markdown and WordPress `readme.txt`
**Testing**: reading each claim against code; `node -e "require('./tools/qa/smoke.js')"`
**Constraints**: no code, no `changelog.txt`, no stable tag change

## Repo gates

`AGENTS.md` docs triggers: behaviour clarification touching cache and consent paths updates `docs/guides/SECURITY-PRIVACY.md`, `docs/reference/INTEGRATIONS.md`, the READMEs and `readme.txt`.

## Design

| Document | Change |
|---|---|
| `docs/guides/SECURITY-PRIVACY.md` | "No-JavaScript boundary" and filter paragraphs in the cached server-rendered attribution section |
| `docs/reference/INTEGRATIONS.md` | one paragraph after the submit-time rule |
| `README.en.md`, `README.pt-BR.md` | one bullet under what it does not do |
| `readme.txt` | one FAQ entry |

## Risks

A claim drifting from code. Mitigation: FR-002 lists the code each claim rests on; a later change to the render filter or the cookie writer must update these passages.
