# Translation and locale rollout

**Status date:** 2026-09-04
**Text domain:** `click-trail-handler`

This document separates bundled translation files from WordPress.org language-pack
status. A `.po`, `.mo`, or JavaScript JSON file in this repository does not mean
that WordPress.org has approved or published that locale. The current POT contains 603 active message IDs.

## Current verified state

| Locale | WordPress.org project | Bundled in GitHub `main` | Notes |
|---|---:|---:|---|
| German (`de_DE`) | 0% | Yes | PHP `.po`/`.mo` and admin JavaScript JSON are present. |
| Brazilian Portuguese (`pt_BR`) | 0% | Yes | PHP `.po`/`.mo` and admin JavaScript JSON files are present. |
| Spanish (Spain, `es_ES`) | 0% | No | Recommended next language after the existing catalogs are refreshed. |
| Dutch (`nl_NL`) | 0% | No | Use Dutch from the Netherlands as the first Dutch locale. |
| Japanese (`ja`) | 0% | No | Needs native review of technical and WordPress terminology. |
| Simplified Chinese (`zh_CN`) | 0% | No | Confirm mainland-China terminology with a native reviewer. |
| Russian (`ru_RU`) | 0% | No | Needs native review. |
| Hindi (`hi_IN`) | 0% | No | “Hindu” is the religion; the requested language is Hindi. |
| English (Australia, `en_AU`) | 0% | No | Regional English is optional; the canonical source is already English. |
| English (UK, `en_GB`) | 0% | No | Regional English is optional; avoid duplicating the source catalog. |
| English (US) | — | Source | US English is the canonical source language, not a separate translation. |

The live project is [ClickTrail on translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/click-trail-handler/).
The stable WordPress.org listing is currently 1.9.1, while GitHub `main` is 1.10.0.
The 1.10.0 package has not been published to WordPress.org from this workflow.

## How WordPress.org translations work

1. Source strings are extracted from PHP and JavaScript into a POT catalog.
2. Translators work in the ClickTrail project on GlotPress.
3. WordPress.org reviews and promotes translations into language packs.
4. WordPress installs the matching language pack for the plugin version.
5. Bundled `.mo` and JavaScript JSON files are useful for GitHub/release ZIPs, but do not replace GlotPress approval.

Do not claim a locale is supported until its language-pack status and the relevant
admin screens have been checked on a localized WordPress installation.

## Recommended rollout

### Phase 1: refresh the existing catalogs

- Regenerate the POT catalog from the current 1.10.0 source.
- Run `msgmerge` for `de_DE` and `pt_BR`.
- Remove or review fuzzy entries before release.
- Recompile `.mo` files with `msgfmt --check`.
- Regenerate the JavaScript JSON catalogs with `wp i18n make-json`.
- Check placeholders, HTML tags, setting names, event names, and technical terms.

### Phase 2: add high-value locales

Start with `es_ES` and `nl_NL`, then `ja` and `zh_CN`. Add `ru_RU` and `hi_IN`
after native reviewers are available. Each locale should have:

- a complete PHP catalog;
- matching JavaScript catalogs for every script passed to `wp_set_script_translations()`;
- a native reviewer for the settings wizard, consent language, diagnostics, and privacy text;
- a localized smoke pass covering activation, setup, settings, diagnostics, and one form or WooCommerce path.

### Phase 3: regional English review

Do not create an artificial `en_US` translation. Review the canonical English copy
once, then create `en_GB` or `en_AU` only when there is a real spelling, legal, or
support need. Regional variants should not change technical identifiers or claims.

## Release checklist

- [ ] Freeze source strings for the release candidate.
- [ ] Generate and review the POT diff.
- [ ] Update existing `.po` files and compile `.mo` files.
- [ ] Generate all required JavaScript JSON catalogs.
- [ ] Validate `%s`/`%d` placeholders, markup, and translator comments.
- [ ] Run the plugin smoke suite and the available PHP CI gates.
- [ ] Build the production ZIP and inspect its top-level directory.
- [ ] Validate the SVN trunk/tag package without changing the existing dirty SVN checkout.
- [ ] Submit locale strings to GlotPress and record the translation status URL.
- [ ] Have a native reviewer approve user-facing copy before advertising the locale.

## Important boundary

WordPress.org SVN publishes plugin code and readme/assets. It does not by itself
publish completed translations. Translation publication belongs to the WordPress.org
GlotPress project and its locale teams. This repository can carry reproducible
catalogs, but cannot substitute for that review process.
