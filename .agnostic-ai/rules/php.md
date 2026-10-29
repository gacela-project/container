---
name: container-php
description: PHP conventions for Gacela Container source and tests.
globs: ["src/**", "tests/**", "benchmarks/**"]
---

Use `declare(strict_types=1)` and match sibling code. Source uses `Gacela\Container`; tests use `GacelaTest` and benchmarks use `GacelaBench` via Composer's PSR-4 mappings.

Keep resolution decisions with the registry, plan, or resolver that owns the data. Reuse existing fixtures under `tests/Fake/`. Trace runtime resolution, validation, and compiled factories when changing wiring so they keep the same observable behaviour.

For behaviour changes, add a focused regression test that fails before the fix. Prefer observable results over reflection into internals. Assert exception classes; exception text is outside the compatibility promise. Test PHP 8.3 and 8.4 paths when lazy-object behaviour changes.

## Validation

- A focused change: run affected PHPUnit tests with `composer test-phpunit -- --filter <test>` and format touched code, then run affected static analysis. `composer csrun` checks formatting; `composer phpstan` and `composer psalm` run the analyzers sequentially.
- Shared resolution, inheritance, caching, compilation, or a broad refactor: use `composer test-all` (format check, Psalm, PHPStan, PHPUnit) when the affected paths warrant it.
- Coverage or tests: use `composer test-coverage-check` when coverage needs verification. The line coverage floor is 95%; PHP 8.4+ covers native lazy objects.
- Mutation-sensitive logic: use Infection when it adds evidence. The configured MSI floors are 87%; do not lower them to pass.
- Performance work: read `tools/bench-compare.sh` before `composer bench:compare -- origin/main`, because it temporarily switches `src` and `benchmarks`. Record the baseline commit, PHP version, opcache/JIT settings, and mode; recheck anomalous results. CI benchmarks are required.
