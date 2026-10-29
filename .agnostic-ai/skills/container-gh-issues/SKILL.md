---
name: container-gh-issues
description: "Process a requested Gacela Container GitHub issue queue or backlog, with optional filters, limits, dry run, or merge policy. Use only when queue processing is requested."
---

# Container issue queue

Paths and commands are relative to the repository root. Use `container-gh-issue` for each issue; do not duplicate its implementation workflow.

Read the arguments from the user's request. A supplied issue number selects only that issue. Otherwise fetch open issues unassigned or assigned to the current authenticated user, deduplicate, and order by creation date. Paginate when necessary. Skip any issue assigned to someone else. Apply the requested label and limit, then show the queue. With `--dry-run`, stop without writes.

Before each issue, reread its assignment and state. Work sequentially unless the user authorizes parallel issue work, with at most four active issues in separate worktrees. Parallelize independent issues, then coordinate integration and final checks. Read every comment and verify current code. Never discard work to refresh main.

Wait for required CI checks. With `--no-merge`, leave the PR open. If the user requested queue processing with merging, squash-merge only after all required checks pass, then refresh main with a fast-forward update. Clean up merged branches and worktrees while preserving unpushed commits and open PRs.

Stop and report a concrete blocker if ownership changes, branch protection blocks merging, a required external decision is missing, or a fix cannot be validated. Stop at the requested limit or an empty queue. Report PRs, acceptance results, and remaining issues.
