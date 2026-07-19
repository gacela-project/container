---
description: Fetch one GitHub issue, create a branch, implement it with TDD, and open a PR that closes it.
argument-hint: "[issue-number]"
disable-model-invocation: true
allowed-tools: "Read, Edit, Write, Bash(gh *), Bash(git *), Bash(composer *), Bash(XDEBUG_MODE=* *), Bash(./vendor/bin/*)"
---

# GitHub Issue Workflow

Single-issue workflow for `gacela-project/container`. To process many issues in a
loop, use `/gh-issues` instead.

## Context

!`gh issue view ${ARGUMENTS#\#} --json number,title,body,labels,assignees,state 2>/dev/null || echo "Provide an issue number"`

## Phase 1 — Setup

1. **Parse the issue number** from `$ARGUMENTS` (strip a leading `#`).

2. **Worktree sanity** — start from a clean, up-to-date `main`:
   ```bash
   git status --porcelain          # must be empty; never auto-stash
   git checkout main && git fetch origin main && git reset --hard origin/main
   ```

3. **Self-assign** (no-op if already yours):
   ```bash
   gh issue edit <num> --add-assignee Chemaclass
   ```

4. **Branch** from `main`. Prefix from the issue's primary label:
   `bug` → `fix/`, `enhancement` → `feat/`, `documentation` → `docs/`,
   `refactoring` → `ref/`, otherwise `feat/`.
   ```bash
   git checkout -b <prefix><num>-<slug>
   ```

## Phase 2 — Plan

5. Read the issue body. These issues are written agent-ready: API sketch,
   implementation notes with file/method refs, and acceptance criteria. Confirm
   the affected files (`src/Container/…`) and the test list before writing code.
   Public methods land on both `Container` and `ContainerInterface`; this project
   does not use `interface` for data types.

## Phase 3 — Implement (TDD)

6. Write failing unit tests under `tests/Unit/` first, then the minimum code in
   `src/Container/`, then refactor while keeping tests green.

7. **Verify locally** (do NOT run Psalm locally — see Rules):
   ```bash
   XDEBUG_MODE=off ./vendor/bin/phpunit
   XDEBUG_MODE=off ./vendor/bin/phpstan analyze --no-progress
   XDEBUG_MODE=off ./vendor/bin/php-cs-fixer fix
   ```
   Fix every failure before proceeding.

## Phase 4 — Ship

8. **Changelog** — add an entry under `## Unreleased` in `CHANGELOG.md`.

9. **Docs** — update the relevant file(s) under `docs/`, plus
   `docs/api-reference.md` if a new public method was added.

10. **Commit** (conventional; `ref:` not `refactor:`; never mention Claude/AI):
    ```bash
    git add <specific-files>
    git commit -m "<type>: <description>" -m "Closes #<num>"
    ```

11. **Open the PR** (assign Chemaclass, label matching the issue type, close the
    issue; no AI/Claude attribution in the body):
    ```bash
    git push -u origin <branch>
    gh pr create --assignee Chemaclass --label <bug|enhancement|documentation> \
      --title "<type>: <description>" \
      --body "<summary>

    Closes #<num>"
    ```

12. **Wait for green CI** before considering it done; fix any red check on the
    branch. Every job must pass (including `Type Checker` = Psalm) **except
    Scrutinizer**, which is slow/external — do NOT wait for it:
    ```bash
    gh pr checks <pr>
    ```

## Checklist

- [ ] Issue fetched and understood
- [ ] Started from clean `main`, self-assigned
- [ ] Branch created with label-based prefix
- [ ] Tests written first (TDD)
- [ ] PHPUnit + PHPStan + CS-Fixer green locally
- [ ] Changelog + docs updated
- [ ] Conventional commit with `Closes #<num>`
- [ ] PR opened (Chemaclass, labeled), CI green

## Rules

- **Psalm can't run locally** (Psalm 5.26 crashes on PHP 8.5). Use PHPUnit +
  PHPStan + CS-Fixer locally; rely on the CI `Type Checker` job for Psalm. Avoid
  `composer quality`/`composer test-all` locally (they include Psalm) — run the
  individual tools above.
- **Fix all CI jobs before merging** — every job green except Scrutinizer (don't wait for Scrutinizer).
- **Never** mention Claude/AI/LLM in commits or PR descriptions, and never add
  attribution trailers.
- Use `Closes #<num>` so the issue auto-closes on merge.
- Signed commits use the configured GPG key; do not disable signing.
