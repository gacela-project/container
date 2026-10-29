---
name: container-release
description: "Publish a Gacela Container release through release.sh when the user explicitly requests a release. Accept an optional X.Y.Z version."
---

# Container release

Use only for an explicit release request. Preparing release notes or configuring this skill does not authorize publication. Paths and commands are relative to the repository root. Canonical automation is `release.sh`; see `.github/RELEASE.md`. Never replace it with manual tag or release commands.

1. Inspect `./release.sh --help`. Verify a clean main at `origin/main`, authenticated `gh`, and nonempty Unreleased notes. Check every required CI job for the exact main commit.
2. Use `container-optimize-changelog` to prepare notes. Select the requested version, or suggest a semantic version from the notes. Breaking changes require a major. The script's default is a minor bump, so pass an explicit version when appropriate.
3. Run `./release.sh X.Y.Z --dry-run` and inspect the exact proposed result. Confirm the version and publication only if the user's request has not already authorized them.
4. Run `./release.sh X.Y.Z`. Preserve signed commits, signed unprefixed tags, and the script's CI gate. Only use `--skip-tests` when explicitly requested.
5. If it fails, inspect the script state before using `./release.sh --rollback`; do not undo a published release blindly.
6. Verify `gh release view X.Y.Z`, the published tag and commit, and Packagist's version. Report the release URL and any verification still pending.
