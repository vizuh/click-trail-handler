# Translation catalogs

The text domain is `click-trail-handler`. This directory contains the source POT
for GitHub and runtime catalogs for release ZIPs. It currently includes `de_DE`
and `pt_BR`; WordPress.org language-pack status is tracked in
[`docs/guides/TRANSLATIONS.md`](../docs/guides/TRANSLATIONS.md).

## File roles

- `.pot`: source catalog regenerated from the current PHP and JavaScript strings.
- `.po`: editable gettext source catalog.
- `.mo`: compiled PHP catalog loaded by WordPress.
- `.json`: compiled JavaScript catalog used by `wp_set_script_translations()`.

WordPress.org translations are submitted through the
[ClickTrail GlotPress project](https://translate.wordpress.org/projects/wp-plugins/click-trail-handler/).
Adding a local catalog does not publish or approve a WordPress.org language pack.
