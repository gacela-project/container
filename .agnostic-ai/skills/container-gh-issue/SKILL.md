---
name: container-gh-issue
description: Use to fix, work or pick up one Gacela Container GitHub issue by number or URL and open its PR.
---

# Container issue workflow

Follow the compatibility, validation and delivery rules in `.agnostic-ai/AGNOSTIC_AI.md`. Use the global `gh-issue` skill only for what this one omits.

1. Read the issue and every comment. Verify each acceptance criterion against the current checkout; old findings are not facts.
2. Fetch `origin/main`. Keep local changes and commits; use a worktree if needed. Never `git reset --hard` main. Self-assign to `Chemaclass`.
3. Branch from main: `fix/<number>-<slug>` (bugs), `feat/` (enhancements), `docs/`, `ref/` (refactors). Confirm acceptance scenario and scope before editing.
4. Implement the smallest complete outcome; verify each criterion.
5. Review the diff before pushing. Signed conventional commit, then a PR assigned to `Chemaclass` with a label and `Closes #<number>`. Pass multiline text by body file or structured argument.
6. Check every required CI job; fix failures within scope. Report PR link, acceptance evidence and validation limits. Merge only if the user asked; otherwise leave the PR for review.
