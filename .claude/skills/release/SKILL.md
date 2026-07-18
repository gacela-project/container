---
description: Create a new versioned Gacela Container release via release.sh (canonical automation)
argument-hint: "[version]"
disable-model-invocation: true
---

# Release

Canonical release automation lives in [`release.sh`](../../../release.sh). Always delegate to it — do not perform release steps manually. See [`.github/RELEASE.md`](../../../.github/RELEASE.md) for full reference.

## Context

!`git branch --show-current`
!`git status --porcelain`
!`git describe --tags --abbrev=0 2>/dev/null || echo "no tags"`

## Instructions

### Phase 1: Pre-flight

1. Abort if not on `main`. Must be clean tree (in-sync with `origin/main`).
2. Confirm `gh` CLI authenticated: `gh auth status`.
3. Confirm `## Unreleased` section in `CHANGELOG.md` has content:
   ```bash
   awk '/^## Unreleased/{flag=1;next} /^## /{flag=0} flag' CHANGELOG.md
   ```
   Abort if empty.
4. Determine version:
   - If `$ARGUMENTS` provides `X.Y.Z`, validate format.
   - Otherwise, suggest bump based on Unreleased content (breaking → major, feat → minor, fix only → patch).

### Phase 2: Dry-run preview

5. Show planned changes first:
   ```bash
   ./release.sh X.Y.Z --dry-run
   ```
   Confirm output with user before proceeding.

### Phase 3: Release

6. Execute:
   ```bash
   ./release.sh X.Y.Z
   ```
   Script handles: rewrite `CHANGELOG.md` (`## Unreleased` → versioned header, insert fresh `## Unreleased`), verify CI is green for HEAD via GitHub check-runs, commit `chore(release): X.Y.Z`, GPG-signed tag `X.Y.Z` (unprefixed), push `main` + tag, create GitHub release with notes from the CHANGELOG section.

7. On failure mid-script, run:
   ```bash
   ./release.sh --rollback
   ```

### Phase 4: Verify

8. Confirm release:
   ```bash
   gh release view X.Y.Z
   ```
9. Report release URL to user. Packagist auto-syncs the new tag via webhook.

## Rules

- **Tags unprefixed**: `0.9.0`, never `v0.9.0`.
- **Commit format**: `chore(release): X.Y.Z`.
- **GPG-signed tags**: `release.sh` uses `git tag -s` (key E51B5BF45F85D160). Never replace with an unsigned tag.
- **Never** run manual `git tag` / `gh release create` when `release.sh` available.
- **CI gate**: the script verifies GitHub check-runs are green for HEAD instead of re-running tests locally. Only bypass with `--skip-tests` if the user explicitly asks.
- No version file to bump — version is derived at runtime from the git tag.
- See `./release.sh --help` for all flags (`--dry-run`, `--force`, `--skip-tests`, `--without-gh-release`).
