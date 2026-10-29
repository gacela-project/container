---
name: container-gh-issue
description: "Implement a Gacela Container GitHub issue identified by number or URL and prepare its PR. Use when asked to fix, work, or pick up one issue."
---

# Container issue workflow

Paths and commands are relative to the repository root. Follow the project compatibility, validation, and delivery rules. This is the repository workflow for issue work; use the global `gh-issue` skill only for guidance it does not cover.

1. Read the issue and every comment, inspect current code, and verify each acceptance criterion against the current checkout. Do not treat old issue findings as current facts.
2. Fetch `origin/main`. Preserve local changes and commits; use an isolated worktree if needed. Never reset main with `--hard`. Self-assign to `Chemaclass` when working the issue.
3. Branch from current main: `fix/<number>-<slug>` for bugs, `feat/` for enhancements, `docs/` for docs, `ref/` for refactors. Confirm the acceptance scenario and scope before editing.
4. Implement the smallest complete outcome and verify each acceptance criterion with the project validation rules.
5. Review the diff before pushing. Use a signed conventional commit with the repo's types, then open a PR assigned to `Chemaclass`, with an appropriate label and `Closes #<number>`. For multiline GitHub text use a body file or structured tool argument.
6. Check every required CI job and fix failures within scope. Report the PR link, acceptance evidence, and validation limits. Merge only within the user's requested scope; otherwise leave a reviewable PR.
