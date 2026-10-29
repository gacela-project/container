---
name: container-gh-issues
description: "Process an explicitly requested queue of gacela-project/container issues. Accept an issue number, --limit N, --label NAME, --dry-run, or --no-merge."
disable-model-invocation: true
---

# Container issue queue

Paths and commands are relative to the repository root. Use `container-gh-issue` for each issue; do not duplicate its implementation workflow.

Read the arguments from the user's request. A supplied issue number selects only that issue. Otherwise fetch open issues unassigned or assigned to the current authenticated user, deduplicate, and order by creation date. Paginate when necessary. Skip any issue assigned to someone else. Apply the requested label and limit, then show the queue. With `--dry-run`, stop without writes.

Before each issue, reread its assignment and state. Work one issue at a time in a clean branch or isolated worktree. Read every comment and verify current code. Never discard work to refresh main.

Wait for required CI checks. With `--no-merge`, leave the PR open. If the user requested queue processing with merging, squash-merge only after all required checks pass, then refresh main with a fast-forward update. Clean up merged branches and worktrees while preserving unpushed commits and open PRs.

Stop and report a concrete blocker if ownership changes, branch protection blocks merging, a required external decision is missing, or a fix cannot be validated. Stop at the requested limit or an empty queue. Report PRs, acceptance results, and remaining issues.
