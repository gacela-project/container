#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root=$(mktemp -d "${TMPDIR:-/tmp}/container-release-version.XXXXXX")

cleanup() {
  [[ "$test_root" == /*/container-release-version.* ]] || return 1
  printf 'Removing %s\n' "$test_root"
  rm -rf "$test_root"
}
trap cleanup EXIT

mkdir "$test_root/bin"
cp "$project_root/release.sh" "$test_root/release.sh"
printf '# Changelog\n\n## Unreleased\n\n### Fixed\n\n- A bug.\n\n## [2.0.2]\n' > "$test_root/CHANGELOG.md"
cp "$test_root/CHANGELOG.md" "$test_root/original-changelog"

cat > "$test_root/bin/git" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
case "$1" in
  status|fetch) ;;
  branch) printf 'main\n' ;;
  describe)
    [[ "$LATEST_TAG" != missing ]] || exit 128
    printf '%s\n' "$LATEST_TAG"
    ;;
  rev-parse)
    [[ "$2" != refs/tags/* ]] || exit 1
    printf 'test-head\n'
    ;;
  *) printf 'Unexpected git command: %s\n' "$*" >&2; exit 2 ;;
esac
SH
cat > "$test_root/bin/gh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == auth ]] || {
  printf 'Unexpected gh command: %s\n' "$*" >&2
  exit 2
}
SH
chmod +x "$test_root/bin/git" "$test_root/bin/gh"

run_release() {
  local tag=$1 expected=$2 actual=0
  shift 2
  (cd "$test_root" && PATH="$test_root/bin:$PATH" LATEST_TAG="$tag" bash ./release.sh --force --dry-run "$@") > "$test_root/output" 2>&1 || actual=$?
  if [[ "$actual" != "$expected" ]]; then
    cat "$test_root/output" >&2
    printf 'Expected exit %s, got %s\n' "$expected" "$actual" >&2
    exit 1
  fi
  cmp "$test_root/original-changelog" "$test_root/CHANGELOG.md"
  [[ ! -e "$test_root/.release-state" ]]
}

for tag in missing v-next; do
  run_release "$tag" 1
  grep -q 'Could not read current version from git tags.' "$test_root/output"
  run_release "$tag" 1 2.1.0
  grep -q 'Could not read current version from git tags.' "$test_root/output"
done

run_release 2.0.2 0
grep -q 'Current: 2.0.2.*New: 2.1.0' "$test_root/output"
run_release 2.0.2 0 2.0.3
grep -q 'Current: 2.0.2.*New: 2.0.3' "$test_root/output"

printf 'Release version scenarios passed.\n'
