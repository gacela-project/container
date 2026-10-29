---
name: container-gh-issue
description: "Work one gacela-project/container GitHub issue through implementation and a PR. Accept an issue number or URL."
disable-model-invocation: true
---

# Container issue workflow

Paths and commands are relative to the repository root. Read `.agnostic-ai/AGNOSTIC_AI.md` for the current compatibility and validation rules. This specializes the global `gh-issue` workflow when it is available.

1. Read the issue and every comment, inspect current code, and verify each acceptance criterion against the current checkout. Do not treat old issue findings as current facts.
2. Fetch `origin/main`. Preserve local changes and commits; use an isolated worktree if needed. Never reset main with `--hard`. Self-assign to `Chemaclass` when working the issue.
3. Branch from current main: `fix/<number>-<slug>` for bugs, `feat/` for enhancements, `docs/` for docs, `ref/` for refactors. Confirm the acceptance scenario and scope before editing.
4. Add a failing regression test for a behaviour change, implement the smallest complete outcome, and verify each acceptance criterion. New public methods must respect the frozen 2.x `ContainerInterface`.
5. Choose local checks from the change and risk using the project validation rules. Psalm runs locally when applicable. Do not run the full PHP, mutation, and benchmark suites for instructions or docs changes.
6. Update relevant docs and the Unreleased changelog for consumer-visible changes. Use `UPGRADE.md` for required caller changes.
7. Review the diff before pushing. Use a signed conventional commit with the repo's types, then open a PR assigned to `Chemaclass`, with an appropriate label and `Closes #<number>`. For multiline GitHub text use a body file or structured tool argument.
8. Check every required CI job and fix failures within scope. Report the PR link, acceptance evidence, and validation limits. Merge only within the user's requested scope; otherwise leave a reviewable PR.
