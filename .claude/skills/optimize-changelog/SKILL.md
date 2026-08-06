---
description: Bring the Unreleased changelog notes up to release shape — account for every commit since the last tag, merge duplicate sections, collapse entries describing one change, tighten to the density the released sections use, verify every doc anchor — and optionally cut the release.
argument-hint: "[--release] [--version X.Y.Z] [--dry-run]"
disable-model-invocation: true
allowed-tools: "Read, Edit, Write, Bash(git *), Bash(gh *), Bash(./release.sh *), Bash(python3 *), Bash(sed *), Bash(grep *), Bash(wc *)"
---

# Optimize the changelog

Run this **before every release**, and any time `## Unreleased` has grown by more
than a couple of entries. Entries are written as a change lands, when its author
knows the most and edits the least — so the section accumulates duplication and
detail that the released sections do not have.

## Context

!`git branch --show-current`
!`git status --porcelain`
!`sed -n '/^## Unreleased/,/^## \[/p' CHANGELOG.md | grep -c '^- ' | xargs echo "unreleased entries:"`
!`sed -n '/^## Unreleased/,/^## \[/p' CHANGELOG.md | wc -w | xargs echo "unreleased words:"`

## Arguments

- `--dry-run` — report what would change; write nothing.
- `--release` — after tightening, cut the release (delegates to the `release` skill / `release.sh`).
- `--version X.Y.Z` — explicit version for `--release`. **Required when anything is marked ⚠**, because `release.sh` auto-bumps the *minor* and a breaking change needs a major.

## Phase 0 — Check the section covers what shipped

Every later phase reads only what is already written, so the failure they cannot
see is silence: a merged PR nobody wrote an entry for.

```bash
git log $(git describe --tags --abbrev=0)..HEAD --oneline
```

Account for every commit against `## Unreleased`. One needs an entry unless it is
a `chore(release):` commit, or has no effect a consumer or a future maintainer
could notice — CI plumbing that changes no result, a `.gitignore` fix, committed
worktree litter. Judgement call, and the bar is *noticeable*, not *code*: a
`docs:` commit that ships a measurement or settles an open question earns a
`### Documentation` entry, and #193 — the 2.x-versus-1.5.0 numbers that closed
#189 — reached a release candidate with none.

## Phase 1 — Measure the target

Density is not a guess. Compute words-per-entry for the last few released
sections and aim at their range:

```bash
for v in $(grep -oE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' CHANGELOG.md | head -3 | tr -d '#[] '); do
  n=$(sed -n "/^## \[$v\]/,/^## \[/p" CHANGELOG.md | grep -c '^- ')
  w=$(sed -n "/^## \[$v\]/,/^## \[/p" CHANGELOG.md | wc -w)
  echo "$v: $n entries, $w words, ~$((w/n)) w/entry"
done
```

Then measure `## Unreleased` the same way. If it is already inside the range and
has no structural problems (Phase 2), say so and stop — do not rewrite prose for
its own sake.

## Phase 2 — Structural problems, in priority order

These matter more than length. Look for each explicitly:

1. **Duplicate `###` headers.** Successive merges each insert their own
   `### Added` / `### Fixed`. Merge them; a section must appear once.
2. **One change described twice.** Two entries for the same PR — typically its
   correctness half under *Fixed* and its performance half under *Performance*.
   Collapse into the section the reader cares about, keeping both facts.
3. **Entries that contradict each other.** A win and a regression on the same
   subject read as a contradiction when adjacent; say which offsets which.
4. **Wrong section.** CI, benchmark plumbing and tooling belong under
   `### Internal`, not `### Performance` or `### Fixed` — the released sections
   set that precedent. A behaviour *widening* belongs under `### Changed`, not
   `### Added`.
5. **Breaking changes not marked.** Every breaking entry gets a leading ⚠, and
   `### Changed` carrying them sorts first, with one line above the sections
   saying who has to act. Callers usually do not.

## Phase 3 — Tighten each entry

Keep, always:

- the **API shape** — method names, signatures, attribute names
- **numbers** — benchmark figures, percentages, memory
- the **doc link**
- the ⚠ marker and what breaks

Cut:

- **mechanism and rationale.** They live in the linked docs and in the commit.
  The changelog says *what changed and why it matters*, not how it works.
- restatements of the problem in more than one sentence
- anything the reader can only act on by reading the linked doc anyway

Never invent a number or a link. If an entry cites a figure you cannot confirm
from the repo, keep it as written.

## Phase 4 — Verify every doc anchor

Slugs are easy to get wrong and nothing else checks them — `#what-1-5-0-cost`
versus the real `#what-150-cost` has shipped before.

```bash
python3 - <<'PY'
import re, os
s = open('CHANGELOG.md').read()
sec = s[s.index('## Unreleased'):]
sec = sec[:sec.index('\n## [')] if '\n## [' in sec else sec
bad = []
for path, anchor in re.findall(r'\]\((docs/[a-z0-9\-]+\.md)#([a-z0-9\-]+)\)', sec):
    if not os.path.isfile(path):
        bad.append((path, anchor, 'missing file')); continue
    slugs = set()
    for h in re.findall(r'^#{1,6}\s+(.*)$', open(path).read(), re.M):
        t = h.lower()
        t = re.sub(r'[`*\[\]()/.,:;?!\'"+—–]', '', t)
        t = re.sub(r'[^a-z0-9\s\-]', '', t)
        slugs.add(re.sub(r'\s+', '-', t.strip()))
    if anchor not in slugs:
        bad.append((path, anchor, 'anchor not found'))
print('links checked:', len(re.findall(r'\]\(docs/', sec)))
print('\n'.join(f'  BAD: {b}' for b in bad) or '  all anchors resolve')
PY
```

Fix any `BAD` line by reading the target heading and recomputing its slug.
Also confirm `UPGRADE.md` exists if any entry links to it.

## Phase 5 — Commit

**Changelog-only edits go straight to `main`. No branch, no PR** — that is the
established convention here.

```bash
git add CHANGELOG.md
git commit -S -m "docs: simplify the unreleased changelog notes" -m "<what you merged, collapsed or moved, and the before/after counts>"
git push
```

Conventional commits; never mention Claude/AI; signed with the configured key.

The body should name the *structural* fixes (which sections merged, which
entries collapsed) rather than saying "tightened prose" — that is the part a
reader of the history cannot reconstruct.

If the edit touches anything outside `CHANGELOG.md`, it is no longer
changelog-only: branch and open a PR as normal.

## Phase 6 — Release, only with `--release`

Delegate to `release.sh`; never hand-roll tag, changelog rewrite or GitHub
release. Before running it:

- `main` must be current and its CI **green on every job** — check, do not assume.
- Choose the version deliberately. `release.sh` with no argument auto-bumps the
  **minor**; a ⚠ entry means it must be a **major** instead.

```bash
gh run list --branch main --limit 1 --json headSha,conclusion
./release.sh --dry-run            # confirm current → new
./release.sh <version>            # interactive; --force only for CI
```

Afterwards verify the artifact actually published — the GitHub release exists
and is not a draft, and Packagist shows the new version.

## Report

State: any commit that had no entry and what you wrote for it, entries and words
before → after, which sections merged, which entries collapsed and why, any
anchor fixed, and — if `--release` was not passed — the version you would use and
whether a ⚠ forces a major.
