---
name: container-optimize-changelog
description: Use to prepare or tighten Gacela Container Unreleased changelog notes, clean up release notes, or suggest a release version (supports --dry-run).
---

# Prepare container release notes

Read `CHANGELOG.md`, the latest tag and commits since it. `--dry-run` reports proposed edits without writing.

List every consumer-visible or maintainer-visible change since the latest tag; skip release bookkeeping and invisible housekeeping. Verify missing entries against code or PR before adding.

Count entries and words per entry in recent released sections and Unreleased, and use that density as the guide. If the notes already fit and are structurally sound, leave them.

Merge duplicate headings. Collapse entries for the same change, keeping correctness and performance facts. Resolve contradictions from their sources. Tooling and CI go under Internal; behaviour widening under Changed; mark breaking entries with a warning and the caller action; put breaking Changed entries first.

Keep API names and signatures, measurements, doc links and breaking markers. Cut detail already in linked docs. Never invent numbers or drop an unverified claim silently.

Verify every relative link and heading anchor in the changed notes against its target, including `UPGRADE.md`. Use GitHub slug rules, including duplicate-heading suffixes. Report factual errors separately.

When asked to commit or publish, changelog-only edits go straight to current main as a signed `docs:` commit; otherwise leave them for review. If other files change, use a branch and PR. Put structural edits and before/after counts in the commit body when needed.

Release only on an explicit request, with `container-release`. Pass an explicit version when breaking changes need a major (the script defaults to minor). Verify the published artifact.

Report entries added, before/after counts, merged sections or entries, corrected anchors, and the suggested version when no release was requested.
