---
name: container-gh-issues
description: Use only when asked to process a queue or backlog of Gacela Container GitHub issues (filters, limit, --dry-run, --no-merge).
---

# Container issue queue

Run `container-gh-issue` for each issue; do not restate its steps.

Queue: a supplied issue number selects only that issue. Otherwise fetch open issues unassigned or assigned to the current user (paginate, deduplicate), oldest first. Skip issues assigned to someone else. Apply the requested label and limit, then show the queue. `--dry-run` stops here without writes.

Before each issue, reread its assignment and state. Work sequentially, or in parallel only if the user allows it: at most four independent issues, each in its own worktree. Never discard work to refresh main.

Wait for required CI. `--no-merge` leaves the PR open. When merging is requested, squash-merge only after all required checks pass, then fast-forward main. Clean up merged branches and worktrees; keep unpushed commits and open PRs.

Stop and report a concrete blocker if ownership changes, branch protection blocks the merge, a decision is missing, or a fix cannot be validated. Stop at the limit or an empty queue. Report PRs, acceptance results and remaining issues.
