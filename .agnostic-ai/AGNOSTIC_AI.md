# Gacela Container

A framework-independent PHP >=8.3 dependency injection container implementing PSR-11. `psr/container` is the only required runtime package; YAML support is optional.

## Project configuration

Edit `.agnostic-ai/`, then run `agnostic-ai sync` and `agnostic-ai sync --check`. Native instructions, skills, rules, and settings are generated and ignored. Run sync in each fresh clone or worktree. Paths in instructions and skills are relative to the repository root.

## Project map

- `src/Container/`: resolution, compilation, validation, registries, attributes, exceptions, and `Console/`.
- `tests/Unit/`: behaviour tests; `tests/Fake/`: shared fixtures and definition files.
- `benchmarks/ContainerBench.php`: performance subjects and regression assertions.
- `docs/`: consumer guides and public contract.
- `composer.json` and `.github/workflows/ci.yml`: current commands, thresholds, and CI gates; these win over older prose.

## Compatibility

Before changing public behaviour, read `docs/backward-compatibility.md` and check `@api` and `@internal` boundaries. Preserve public signatures and parameter names. `ContainerInterface` is frozen in 2.x: new `Container` methods stay off it until 3.0. `FullContainerInterface` remains a deprecated alias until 3.0.

Keep PHP 8.3 syntax. Native lazy objects require PHP 8.4; preserve the guarded 8.3 fallback. Compiled caches are version-specific artifacts: regenerate them, never commit or hand-edit them.

## Validation

Choose checks for the affected behaviour and risk once the change set is complete. Do not repeat passing checks without a new reason. PHP-specific tests, analyzers, coverage, mutation, and benchmark guidance lives in `.agnostic-ai/rules/php.md`; read it for PHP work if scoped instructions were not loaded.

For docs or configuration, validate affected links and configuration. For AI configuration run `agnostic-ai validate`, `agnostic-ai lint --strict`, `agnostic-ai sync --check`, and `agnostic-ai doctor --scope project --check-references --check-globs`. No PHP suites are needed unless runtime behaviour changes.

Before merging, required CI checks must pass. CI tests PHP 8.3, 8.4, and 8.5 with lowest and highest dependencies on Ubuntu. Report local checks and remaining limits.

## Delivery

Branch from current `main`, preserving local work. Use signed conventional commits with history's types (`ref:`, not `refactor:`). Consumer-visible changes need an Unreleased `CHANGELOG.md` entry and relevant docs; add `UPGRADE.md` examples when callers must act.

Use `container-gh-issue` for one GitHub issue, `container-gh-issues` for a requested queue, `container-optimize-changelog` for release notes, and `container-release` for a requested release. Releases use `release.sh` and signed unprefixed tags; verify the published artifact.
