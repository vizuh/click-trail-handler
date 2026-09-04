# Translation and cross-repository contribution path

Use this path for ClickTrail and as the model for other plugin repositories. It keeps
machine-assisted drafts, reviewed runtime catalogs, and WordPress.org publication
separate.

## Translation path

1. Start from the repository's current default branch and record the source commit.
2. Regenerate the plugin POT from the current PHP and JavaScript sources.
3. Create one branch per locale batch, such as `i18n/es-nl-drafts`.
4. Store machine-assisted work only in `languages/drafts/`. Keep every entry fuzzy.
5. Run the repository's catalog checks: POT key matching, `msgfmt --check`, placeholders,
   HTML, URLs, entities, technical identifiers, and plural forms.
6. Open a GitHub PR labelled as draft/review-only. Use small batches: one or two locales.
7. A native reviewer edits and verifies user-facing copy, privacy wording, settings,
   diagnostics, and technical terms. Machine output is not approval.
8. After native review, move only the accepted catalogs into the runtime `languages/`
   path, then compile `.mo` and JavaScript `.json` files with the repository tools.
9. Submit the reviewed strings to the plugin's WordPress.org GlotPress project. Record
   the locale URL and language-pack status separately from the GitHub PR.

## Publication boundaries

- GitHub can carry POT files, review-only drafts, and reviewed reproducible catalogs.
- `languages/drafts/` must be excluded from production ZIPs and WordPress.org SVN.
- WordPress.org SVN publication does not approve translations.
- GlotPress and the locale team control translation approval and language packs.
- Do not advertise a locale as supported until native review, runtime smoke testing,
  and the relevant WordPress.org language-pack status are verified.

## General contribution path across repositories

1. Confirm the repository and its nearest `AGENTS.md`/README before editing.
2. Create a focused feature branch; preserve unrelated worktree changes.
3. Make the smallest reviewable change and add a behavior-level test when logic changes.
4. Update the nearest source-of-truth documentation and changelog when needed.
5. Run the repository's documented checks and inspect the final diff.
6. Open a focused GitHub PR with scope, verification, limitations, and rollback notes.
7. Merge only after review and CI. Never push directly to a default branch unless the
   repository's documented workflow explicitly requires it.

## Contribution record

Every locale PR should state:

- repository, text domain, locale, source commit, and changed paths;
- whether entries are machine-assisted, natively reviewed, or runtime-ready;
- checks run and known unavailable checks;
- native reviewer or review request;
- GlotPress project URL and language-pack status;
- explicit confirmation that no SVN publication or translation approval was performed.

For this repository, the locale-specific status and release checklist live in
[`TRANSLATIONS.md`](TRANSLATIONS.md). General code and documentation contributions
start in [`../../CONTRIBUTING.md`](../../CONTRIBUTING.md).
