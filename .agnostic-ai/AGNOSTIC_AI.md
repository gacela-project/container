# Gacela Container

A PHP >=8.3 dependency injection container implementing PSR-11. `psr/container` is the only required runtime package; YAML support is optional. Keep the library independent of frameworks.

## Source configuration

Edit `.agnostic-ai/`, then run `agnostic-ai sync` and `agnostic-ai sync --check`. `CLAUDE.md`, `AGENTS.md`, native skills, rules, and settings are generated and ignored. Paths in these instructions and skills are relative to the repository root, regardless of where a generated file lives.

## Project map

- `src/Container/`: public container, resolution, compilation, validation, registries, attributes, exceptions, and the CLI under `Console/`.
- `tests/Unit/`: behaviour tests; `tests/Fake/`: shared fixtures, including definition files.
- `benchmarks/ContainerBench.php`: performance subjects and regression assertions.
- `docs/`: user guides and API reference. `docs/backward-compatibility.md` defines the public contract.
- `composer.json`: local commands. `.github/workflows/ci.yml`: current CI gates. Prefer these over older prose when commands or thresholds disagree.

## Compatibility

Read `docs/backward-compatibility.md` before changing public behaviour. Preserve public signatures and parameter names. `ContainerInterface` is frozen throughout 2.x: new `Container` methods stay off the interface until 3.0. `FullContainerInterface` is a deprecated alias until 3.0. Check `@api` and `@internal` boundaries.

Keep PHP 8.3 syntax compatibility. Native lazy objects require PHP 8.4; preserve the guarded fallback on 8.3. Compiled cache formats are version-specific artifacts: regenerate them, never commit or hand-edit them.

## Validation

Choose checks from the affected behaviour and risk. Do not run every suite for every change, or repeat passing checks without a new reason.

- Instructions, docs, or configuration only: validate the affected configuration and links. For AI configuration, run `agnostic-ai validate`, `agnostic-ai lint --strict`, `agnostic-ai sync --check`, and `agnostic-ai doctor --scope project --check-references --check-globs`. PHP suites are unnecessary unless runtime behaviour changes.
- A focused PHP change: run the affected PHPUnit tests with `composer test-phpunit -- --filter <test>`, format touched code, then run the affected static analysis. `composer csrun` checks formatting; `composer phpstan` and `composer psalm` run the analyzers sequentially.
- Shared resolution, inheritance, caching, compilation, or a broad refactor: broaden to `composer test-all` (format check, Psalm, PHPStan, PHPUnit) when the affected paths warrant it.
- Coverage or test changes: use `composer test-coverage-check` when coverage needs verification; use PHP 8.4+ to cover native lazy objects. The line coverage floor is 95%.
- Mutation-sensitive logic: use Infection when it adds evidence for the change; the configured MSI floors are 87%. Do not lower them to pass.
- Performance work: use `composer bench:compare -- origin/main`, record the baseline commit, PHP version, opcache/JIT settings, and mode; recheck anomalous results. Read `tools/bench-compare.sh` first because it temporarily switches `src` and `benchmarks`. CI benchmarks are required.

Before merging, all required CI checks must pass. CI tests PHP 8.3, 8.4, and 8.5 with lowest and highest dependencies on Ubuntu. State exactly which local checks ran and any remaining limits.

## Delivery

Use branches from current `main` without discarding local work. Conventional commit types follow history (`ref:`, not `refactor:`). Keep configured commit signing. Add consumer-visible changes under `## Unreleased` in `CHANGELOG.md`, update relevant `docs/`, and add migration examples to `UPGRADE.md` when callers must act.

Use `container-gh-issue` for this repo's issue workflow and `container-gh-issues` for an explicitly requested queue. Use `container-optimize-changelog` to prepare release notes, and `container-release` for a requested release. Run `release.sh` for releases, using unprefixed signed tags. Verify the published release.
