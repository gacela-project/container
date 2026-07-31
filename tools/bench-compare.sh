#!/usr/bin/env sh
#
# A/B this working tree against a git ref, the way CI does it.
#
# Every performance change in this repository has needed this and each one has
# hand-rolled it, usually without the warm-up — which is the step that matters,
# because the first phpbench invocation of a session measures several times
# noisier than the second (#132). Comparing a cold run against a warm one
# reports a regression that is not there, and hides one that is.
#
# Usage:
#   composer bench:compare                 # against main
#   composer bench:compare -- origin/main  # against something else
#   BENCH_FILTER=benchResolve composer bench:compare
#
# POSIX sh on purpose: no bashisms, no GNU-only flags, no sed -i.

set -eu

REF="${1:-main}"
FILTER="${BENCH_FILTER:-}"
PHPBENCH="vendor/bin/phpbench"

if [ ! -x "$PHPBENCH" ]; then
    echo "phpbench not found. Run 'composer install' first." >&2
    exit 1
fi

if ! git rev-parse --verify --quiet "$REF" >/dev/null; then
    echo "Unknown git ref '$REF'." >&2
    exit 1
fi

filter_args=""
if [ -n "$FILTER" ]; then
    filter_args="--filter=$FILTER"
fi

# The whole point is to measure *uncommitted* work, so it has to be put
# somewhere safe first. `git checkout HEAD -- src benchmarks` on its own would
# throw it away, silently, which is the one thing this script must never do.
STASHED=0
CHECKED_OUT=0

# Both flags are cleared as they are acted on, so running this twice — once
# inline, once from the EXIT trap — cannot undo what the first call restored.
restore() {
    if [ "$CHECKED_OUT" = "1" ]; then
        CHECKED_OUT=0
        git checkout HEAD -- src benchmarks 2>/dev/null || true
    fi

    if [ "$STASHED" = "1" ]; then
        STASHED=0
        git stash pop --quiet || {
            echo >&2
            echo "Could not restore your changes automatically." >&2
            echo "They are safe: recover them with 'git stash pop'." >&2
        }
    fi
}
trap restore EXIT INT TERM

if ! git diff --quiet -- src benchmarks || ! git diff --cached --quiet -- src benchmarks; then
    git stash push --quiet -- src benchmarks
    STASHED=1
fi

echo "==> Warming the caches (discarded)"
# shellcheck disable=SC2086
XDEBUG_MODE=off "$PHPBENCH" run $filter_args --progress=none --iterations=1 >/dev/null 2>&1 || true

echo "==> Measuring '$REF'"
git checkout "$REF" -- src benchmarks
CHECKED_OUT=1
# shellcheck disable=SC2086
XDEBUG_MODE=off "$PHPBENCH" run $filter_args --tag=bench_compare_base --store --progress=none >/dev/null

# Back to the working tree, uncommitted changes included, before measuring it.
restore

echo "==> Measuring the working tree"
# shellcheck disable=SC2086
XDEBUG_MODE=off "$PHPBENCH" run $filter_args --ref=bench_compare_base --report=aggregate
