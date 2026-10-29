---
name: container-optimize-changelog
description: "Prepare Gacela Container Unreleased notes: account for commits, merge duplicate entries, tighten prose, and verify links. Accept --dry-run, --release, or --version X.Y.Z."
disable-model-invocation: true
---

# Prepare container release notes

Paths and commands are relative to the repository root. Read `CHANGELOG.md`, the latest tag, and commits since that tag. With `--dry-run`, report proposed edits without writing.

Account for each consumer-visible or maintainer-visible change since the latest tag. Exclude release bookkeeping and invisible housekeeping. Verify missing entries against the code or PR before adding them.

Measure entry counts and words per entry for recent released sections and Unreleased. Use that density as guidance. If the notes already fit and have no structural problems, leave them alone.

Merge duplicate headings. Collapse entries describing the same change, preserving both correctness and performance facts. Resolve contradictions by checking their sources. Put tooling and CI under Internal, behaviour widening under Changed, and mark breaking entries with a warning and a clear caller action. Put breaking Changed entries first.

Keep API names and signatures, measurements, doc links, and breaking-change markers. Cut implementation detail already explained in linked docs. Never invent numbers or silently discard an unverified claim.

Verify every relative link and heading anchor in the changed notes against its actual target, including `UPGRADE.md`. Use GitHub's heading slug rules, including duplicate-heading suffixes. Report factual errors separately.

Changelog-only edits may go directly to current main when committing or publishing is requested, using a signed `docs:` commit. Otherwise leave edits for review. If other files change, use a branch and PR. Put the structural edits and before/after counts in the commit body when needed.

Only with an explicit release request, use `container-release`. An explicit version is needed when breaking changes require a major instead of the script's default minor. Verify the published artifact.

Report missing entries added, before/after counts, merged sections or entries, corrected anchors, and the suggested version when a release was not requested.
