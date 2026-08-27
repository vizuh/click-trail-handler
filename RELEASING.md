# Release Workflow

ClickTrail maintains a 3-version buffer between internal development and the live WordPress.org listing. This gives 3–5 days of real-site testing before any version reaches public users.

---

## Version Buffer Rule

| State | Example |
|-------|---------|
| Active development | 1.8.x |
| Staging / testing | 1.7.2 – 1.7.x |
| Released on WP.org | 1.7.1 |

When internal work reaches version N, the version at N-3 (or the last stable tested version) is pushed to WordPress.org subversion.

---

## Folder Structure

```
dist/
  releases/
    1.7.0/
      click-trail-handler-1.7.0.zip   ← canonical zip for this version
      CHANGELOG.md                     ← what changed in this version
    1.7.1/
      click-trail-handler-1.7.1.zip
      CHANGELOG.md
    1.7.2/                             ← created when that version is cut
      ...
```

Each release folder is created when the version is cut. The zip is built from the repo root, not from `dist/` itself.

---

## Cutting a New Release

1. **Bump the version** in `clicutcl.php`. For a WordPress.org release candidate, also set
   `readme.txt`'s `Stable tag` before building; for a GitHub-only mainline release, leave it at
   the current public WordPress.org version. Do not upload the candidate until every quality
   gate below passes.
2. **Write the changelog** entry in `changelog.txt` and `readme.txt`.
3. **Build the zip** with the canonical packaging script:
   ```bash
   npm run make-zip
   ```
   The archive must include runtime `config/feature-registry.json`, exclude development-only
   `config/feature-test-matrix.json`, and follow the exclusions in `.distignore` and
   `tools/release/make-zip.ps1`.
4. **Create the release folder**:
   ```
   dist/releases/VERSION/click-trail-handler-VERSION.zip
   dist/releases/VERSION/CHANGELOG.md
   ```
5. **Tag and publish the GitHub release** (GitHub always releases immediately at cut time — it runs ahead of WP.org by design):
   ```bash
   git tag vVERSION && git push origin vVERSION
   gh release create vVERSION dist/releases/VERSION/click-trail-handler-VERSION.zip \
     --title "vVERSION — <one-line summary>" \
     --notes-file dist/releases/VERSION/CHANGELOG.md
   ```
   For a maintenance line older than the current GitHub release, add `--latest=false`. Never
   replace the current mainline release's Latest marker with an older maintenance release.
6. **Test on staging** (tallk.me or equivalent) for 3–5 days.

The cadence: mainline cuts are released on GitHub the same day; WP.org receives the oldest staged version after its 3–5 day window. Maintenance-line GitHub releases stay non-latest, so the current mainline release keeps the Latest marker while WP.org users get the tested maintenance build.

---

## Pushing to WordPress.org Subversion

Only run this when the version has passed staging. Replace `VERSION` with the version you are releasing.

WordPress.org icons, banners, and screenshots are version-independent. Keep them only in the SVN checkout's
top-level `assets/` directory; never copy them into `trunk/` or `tags/VERSION/`. Every release must run the
asset validator below so future versions retain the canonical names, dimensions, size limits, and MIME types.

```bash
# Check out the SVN repo (only needed once)
svn co https://plugins.svn.wordpress.org/click-trail-handler/ /tmp/svn-clicktrail

# Copy the new release into the SVN tags folder
mkdir -p /tmp/svn-clicktrail/tags/VERSION
unzip dist/releases/VERSION/click-trail-handler-VERSION.zip -d /tmp/unzipped
cp -r /tmp/unzipped/click-trail-handler/* /tmp/svn-clicktrail/tags/VERSION/

# Update the trunk with the same files
rsync -a --delete /tmp/svn-clicktrail/tags/VERSION/ /tmp/svn-clicktrail/trunk/

# Update Stable tag in readme.txt to VERSION
# (edit trunk/readme.txt Stable tag line manually or via sed)
sed -i "s/^Stable tag:.*/Stable tag: VERSION/" /tmp/svn-clicktrail/trunk/readme.txt

# Validate the shared directory assets before every release commit
python3 tools/release/validate-wporg-assets.py /tmp/svn-clicktrail

# Commit
cd /tmp/svn-clicktrail
svn add --force tags/VERSION
svn ci -m "Release VERSION"
```

---

## Version Naming

| Change type | Bump |
|-------------|------|
| Bug fix, docs, minor improvement | Patch: 1.7.1 → 1.7.2 |
| New feature, backward-compatible | Minor: 1.7.x → 1.8.0 |
| Breaking change or major refactor | Major: 1.x → 2.0.0 |

---

## What goes in changelog.txt vs readme.txt

- `changelog.txt` — full technical detail, all files changed, audience is developers
- `readme.txt` `== Changelog ==` — user-facing summary, one line per meaningful user impact, audience is WordPress.org visitors
- `dist/releases/VERSION/CHANGELOG.md` — same as `changelog.txt` entry but formatted for GitHub/release notes

---

## Quality gates before any WP.org push

Do not promote a release until every applicable gate passes:

- [ ] All PHP files end with `}` (run integrity check: `for f in $(find includes -name "*.php"); do last=$(tail -1 "$f" | tr -d '[:space:]'); ... done`)
- [ ] PHPUnit passes on PHP 8.1, 8.2, and 8.3
- [ ] PHPCS passes with zero warnings
- [ ] PHPCompatibilityWP passes for PHP 8.1+
- [ ] Release archive includes runtime config and excludes tests, tooling, and other development files
- [ ] Plugin activates cleanly on a fresh WordPress install
- [ ] Setup wizard redirect fires on activation
- [ ] A browser submission through the affected provider stores the expected `ct_*` metadata in its provider entry; record the exact WordPress, PHP, provider, and consent-path scope in the canonical integration reference
- [ ] `Stable tag` in `readme.txt` updated to the version being released
- [ ] WordPress.org asset validator above passes against the SVN checkout
