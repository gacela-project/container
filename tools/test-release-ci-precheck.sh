#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root=$(mktemp -d "${TMPDIR:-/tmp}/container-release-ci.XXXXXX")

cleanup() {
  [[ "$test_root" == /*/container-release-ci.* ]] || return 1
  printf 'Removing %s\n' "$test_root"
  rm -rf "$test_root"
}
trap cleanup EXIT

mkdir "$test_root/bin"
cat > "$test_root/bin/git" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'git %s\n' "$*" >> calls
case "$1" in
  status) cmp -s CHANGELOG.md original-changelog || printf ' M CHANGELOG.md\n' ;;
  branch) printf 'main\n' ;;
  describe) printf '2.0.2\n' ;;
  rev-parse)
    [[ "$2" != refs/tags/* ]] || exit 1
    printf 'test-head\n'
    ;;
  fetch|add|commit|tag|push) ;;
  *) printf 'Unexpected git command: %s\n' "$*" >&2; exit 2 ;;
esac
SH
cat > "$test_root/bin/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'gh %s\n' "$*" >> calls
case "$1" in
  auth|release) ;;
  api)
    if [[ "$*" == *length* ]]; then
      [[ "$CI_STATE" != missing ]] && printf '1\n' || printf '0\n'
    elif [[ "$CI_STATE" != success ]]; then
      printf 'Tests: %s\n' "$CI_STATE"
    fi
    ;;
  *) printf 'Unexpected gh command: %s\n' "$*" >&2; exit 2 ;;
esac
SH
chmod +x "$test_root/bin/git" "$test_root/bin/gh"

prepare_fixture() {
  fixture="$test_root/$1"
  mkdir "$fixture"
  cp "$project_root/release.sh" "$fixture/release.sh"
  printf '# Changelog\n\n## Unreleased\n\n### Fixed\n\n- A bug.\n\n## [2.0.2]\n' > "$fixture/CHANGELOG.md"
  cp "$fixture/CHANGELOG.md" "$fixture/original-changelog"
}

run_release() {
  local state=$1 expected=$2 actual=0
  shift 2
  (cd "$fixture" && PATH="$test_root/bin:$PATH" CI_STATE="$state" bash ./release.sh 2.0.3 --force "$@") > "$fixture/output" 2>&1 || actual=$?
  if [[ "$actual" != "$expected" ]]; then
    cat "$fixture/output" >&2
    printf 'Expected exit %s, got %s\n' "$expected" "$actual" >&2
    exit 1
  fi
}

assert_untouched() {
  cmp "$fixture/original-changelog" "$fixture/CHANGELOG.md"
  [[ ! -e "$fixture/.release-state" ]] || {
    printf 'Release state was created before CI passed\n' >&2
    exit 1
  }
}

assert_no_ci_request() {
  if grep -q '^gh api ' "$fixture/calls"; then
    printf 'CI was queried despite an explicit bypass\n' >&2
    exit 1
  fi
}

for state in pending failure missing; do
  prepare_fixture "$state"
  run_release "$state" 1
  assert_untouched
  run_release success 0
  grep -q '^## \[2.0.3\]' "$fixture/CHANGELOG.md"
  [[ -d "$fixture/.release-state" ]]
done

prepare_fixture dry-run
run_release pending 0 --dry-run
assert_untouched
assert_no_ci_request

prepare_fixture skip-tests
run_release failure 0 --skip-tests
grep -q '^## \[2.0.3\]' "$fixture/CHANGELOG.md"
assert_no_ci_request

printf 'Release CI precheck scenarios passed.\n'
