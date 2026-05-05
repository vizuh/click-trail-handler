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

1. **Bump the version** in `clicutcl.php` and `readme.txt` (`Stable tag` stays at the last WP.org release until you push).
2. **Write the changelog** entry in `changelog.txt` and `readme.txt`.
3. **Build the zip**:
   ```bash
   rsync -a --delete \
     --exclude='.git' --exclude='.github' --exclude='node_modules' \
     --exclude='vendor' --exclude='dist' --exclude='*.log' \
     --exclude='.claude' --exclude='.claude-flow' \
     --exclude='AGENTS.md' --exclude='CLAUDE.md' \
     --exclude='composer.json' --exclude='composer.lock' \
     --exclude='package.json' --exclude='package-lock.json' \
     --exclude='phpcs.xml' --exclude='phpunit.xml' \
     --exclude='tests/' \
     . /tmp/click-trail-handler/
   cd /tmp && zip -r click-trail-handler-VERSION.zip click-trail-handler/ -q
   ```
4. **Create the release folder**:
   ```
   dist/releases/VERSION/click-trail-handler-VERSION.zip
   dist/releases/VERSION/CHANGELOG.md
   ```
5. **Test on staging** (tallk.me or equivalent) for 3–5 days.

---

## Pushing to WordPress.org Subversion

Only run this when the version has passed staging. Replace `VERSION` with the version you are releasing.

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

- [ ] All PHP files end with `}` (run integrity check: `for f in $(find includes -name "*.php"); do last=$(tail -1 "$f" | tr -d '[:space:]'); ... done`)
- [ ] PHPCS: zero warnings
- [ ] Plugin activates cleanly on a fresh install
- [ ] Setup wizard redirect fires on activation
- [ ] At least one form submit confirmed with `ct_utm_source` in the entry
- [ ] `Stable tag` in `readme.txt` updated to the version being released
