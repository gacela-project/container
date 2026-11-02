---
name: container-release
description: Use only on an explicit request to publish a Gacela Container release (optional X.Y.Z version).
---

# Container release

Preparing notes or configuring this skill does not authorize publication. Use `release.sh` (see `.github/RELEASE.md`); never replace it with manual tag or release commands.

1. Run `./release.sh --help`. Verify clean main at `origin/main`, authenticated `gh`, non-empty Unreleased notes, and every required CI job green on that commit.
2. Prepare notes with `container-optimize-changelog`. Use the requested version, or suggest one from the notes. Breaking changes need a major; the script defaults to minor, so pass the version explicitly.
3. Run `./release.sh X.Y.Z --dry-run` and inspect the result. Confirm version and publication unless the request already authorized them.
4. Run `./release.sh X.Y.Z`. Keep signed commits, signed unprefixed tags and the CI gate; use `--skip-tests` only when explicitly requested.
5. On failure, inspect the script state before `./release.sh --rollback`; never undo a published release blindly.
6. Verify `gh release view X.Y.Z`, the published tag and commit, and the Packagist version. Report the release URL and anything still pending.
