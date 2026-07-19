---
description: Process open GitHub issues (unassigned or assigned to you) one after another — branch, implement with TDD, open a PR, and merge once CI is green.
argument-hint: "[issue-number] [--limit N] [--label foo] [--dry-run] [--no-merge]"
disable-model-invocation: true
allowed-tools: "Read, Edit, Write, Bash(gh *), Bash(git *), Bash(composer *), Bash(XDEBUG_MODE=* *), Bash(./vendor/bin/*)"
---

# GitHub Issues Worker

Self-contained workflow for `gacela-project/container`. Processes one issue (if a
number is given) or walks every eligible open issue, implementing each end-to-end.

## Args

- `<issue-number>` — process just that issue (strip a leading `#`).
- `--limit N` — process at most N issues (loop mode).
- `--label foo` — only issues carrying label `foo`.
- `--dry-run` — print the queue only; no assignment, branch, or commit.
- `--no-merge` — implement and open the PR, but stop before merging.

## Context

!`git branch --show-current`
!`git status --porcelain`
!`gh api user -q .login`

## Phase 1 — Discover

If an issue number was passed, the queue is just that issue. Otherwise fetch open
issues that are **unassigned** or **assigned to `@me`**, oldest first (merge two
queries, dedupe by number):

```bash
gh issue list --state open --search "no:assignee" --json number,title,labels,assignees,createdAt --limit 200
gh issue list --state open --assignee "@me"        --json number,title,labels,assignees,createdAt --limit 200
```

- Drop issues assigned to anyone other than the current user.
- Apply `--label` / `--limit` if given, sort ascending by `createdAt` (FIFO).
- Print the queue (`#<num> <title> [unassigned|@me]`). If empty, exit cleanly.
- With `--dry-run`, stop here.

## Phase 2 — Worktree sanity

```bash
git status --porcelain          # must be empty; never auto-stash
git fetch origin main
git checkout main && git reset --hard origin/main
```

Abort if the worktree is dirty.

## Phase 3 — Per issue

For each issue in the queue:

1. **Re-check assignment** (someone may have grabbed it):
   ```bash
   gh issue view <num> --json assignees -q '.assignees[].login'
   ```
   Empty → proceed. Only you → proceed. Anyone else → skip.

2. **Self-assign** (no-op if already yours):
   ```bash
   gh issue edit <num> --add-assignee Chemaclass
   ```

3. **Branch** from fresh `main`. Prefix from the issue's primary label:
   `bug` → `fix/`, `enhancement` → `feat/`, `documentation` → `docs/`,
   `refactoring` → `ref/`, otherwise `feat/`.
   ```bash
   git checkout -b <prefix><num>-<slug>
   ```

4. **Plan.** Read the issue body — these issues are written agent-ready with an
   API sketch, implementation notes (file/method refs), and acceptance criteria.
   Confirm the affected files and the test list before writing code.

5. **Implement (TDD).** Write failing unit tests under `tests/Unit/` first, then
   the minimum code in `src/Container/`, then refactor. Keep the public surface
   consistent: new methods go on both `Container` and `ContainerInterface`; this
   project does not use `interface` for data types.

6. **Verify locally** (see Rules — do NOT run Psalm locally):
   ```bash
   XDEBUG_MODE=off ./vendor/bin/phpunit
   XDEBUG_MODE=off ./vendor/bin/phpstan analyze --no-progress
   XDEBUG_MODE=off ./vendor/bin/php-cs-fixer fix
   ```
   Fix every failure before committing.

7. **Changelog.** Add an entry under `## Unreleased` in `CHANGELOG.md`.

8. **Docs.** Update the relevant file(s) under `docs/` and, if a new public
   method was added, `docs/api-reference.md`.

9. **Commit** (conventional; `ref:` not `refactor:`; never mention Claude/AI):
   ```bash
   git add <specific-files>
   git commit -m "<type>: <description>" -m "Closes #<num>"
   ```

10. **Open the PR** (assign Chemaclass, label matching the issue type, close the
    issue; no AI/Claude attribution in the body):
    ```bash
    git push -u origin <branch>
    gh pr create --assignee Chemaclass --label <bug|enhancement|documentation> \
      --title "<type>: <description>" \
      --body "<summary>

    Closes #<num>"
    ```

## Phase 4 — CI & merge

11. **Wait for green CI** and fix any red check on the branch before merging
    (all jobs must pass, including the `Type Checker` = Psalm):
    ```bash
    gh pr checks <pr> --watch
    ```

12. **Merge** (skip if `--no-merge`):
    ```bash
    gh pr merge <pr> --squash
    ```
    Then sync main for the next iteration:
    ```bash
    git checkout main && git fetch origin main && git reset --hard origin/main
    ```

## Stop conditions

Halt and report (never retry blindly) when: the worktree is left dirty, tests fail
after implementation, CI stays red after one fix attempt, merge is blocked by
branch protection, `--limit` is reached, or the queue is empty.

## Rules

- **Psalm can't run locally** (Psalm 5.26 crashes on PHP 8.5). Validate with
  PHPUnit + PHPStan + CS-Fixer locally; rely on the CI `Type Checker` job for
  Psalm. `composer quality`/`composer test-all` include Psalm, so avoid them
  locally — run the individual tools above.
- **Fix all CI jobs before merging** any PR.
- **Never** mention Claude/AI/LLM in commits or PR descriptions.
- **Never** add `Co-Authored-By` or attribution trailers.
- Use `Closes #<num>` in the PR body to auto-close the issue on merge.
- Keep a bundled change in one PR unless the issue explicitly asks otherwise.
- Signed commits use the configured GPG key; do not disable signing.
