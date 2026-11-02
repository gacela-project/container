# Gacela Container

Framework-independent PHP >=8.3 dependency injection container implementing PSR-11. `psr/container` is the only required runtime package; YAML support is optional.

## Project configuration

Edit `.agnostic-ai/`, then run `agnostic-ai sync` and `agnostic-ai sync --check`. Generated instructions, skills, rules and settings are ignored; run sync in each fresh clone or worktree. Paths are relative to the repository root.

## Project map

- `src/Container/`: resolution, compilation, validation, registries, attributes, exceptions, `Console/`.
- `tests/Unit/`: behaviour tests; `tests/Fake/`: shared fixtures and definition files.
- `benchmarks/ContainerBench.php`: performance subjects and regression assertions.
- `docs/`: consumer guides and public contract.
- `composer.json` and `.github/workflows/ci.yml`: commands, thresholds and CI gates; they win over prose.

## Compatibility

Before changing public behaviour, read `docs/backward-compatibility.md` and check `@api` and `@internal` boundaries. Keep public signatures and parameter names. `ContainerInterface` is frozen in 2.x: new `Container` methods stay off it until 3.0. `FullContainerInterface` stays a deprecated alias until 3.0.

Keep PHP 8.3 syntax. Native lazy objects need PHP 8.4; keep the guarded 8.3 fallback. Compiled caches are version-specific: regenerate them, never commit or hand-edit them.

## Validation

Run checks once, after the change set is complete; do not repeat a passing check without a new reason. PHP test, analyzer, coverage, mutation and benchmark commands are in `.agnostic-ai/rules/php.md`; read it for PHP work if it was not loaded.

Docs or configuration: validate affected links and config. AI configuration: `agnostic-ai validate`, `agnostic-ai lint --strict`, `agnostic-ai sync --check`, `agnostic-ai doctor --scope project --check-references --check-globs`. No PHP suites unless runtime behaviour changes.

Required CI checks must pass before merging. CI tests PHP 8.3, 8.4 and 8.5 with lowest and highest dependencies on Ubuntu. Report local checks and remaining limits.

## Delivery

Branch from current `main`, keeping local work. Use signed conventional commits with the history's types (`ref:`, not `refactor:`). Consumer-visible changes need an Unreleased `CHANGELOG.md` entry and docs; add `UPGRADE.md` examples when callers must act.

Skills: `container-gh-issue` (one issue), `container-gh-issues` (queue), `container-optimize-changelog` (release notes), `container-release` (requested release). Releases use `release.sh` and signed unprefixed tags; verify the published artifact.
