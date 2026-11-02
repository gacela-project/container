---
name: container-php
description: PHP conventions for Gacela Container source and tests.
globs: ["src/**", "tests/**", "benchmarks/**"]
---

Use `declare(strict_types=1)` and match sibling code. Composer PSR-4: source `Gacela\Container`, tests `GacelaTest`, benchmarks `GacelaBench`.

Keep resolution decisions in the registry, plan or resolver that owns the data. Reuse fixtures under `tests/Fake/`. When changing wiring, trace runtime resolution, validation and compiled factories so they behave the same.

Behaviour changes need a focused regression test that fails before the fix. Assert observable results, not internals, and exception classes, not text (text is outside the compatibility promise). Test PHP 8.3 and 8.4 paths when lazy-object behaviour changes.

## Validation

- Focused change: `composer test-phpunit -- --filter <test>`, format touched code (`composer csrun` checks), then affected static analysis (`composer phpstan`, `composer psalm`; run sequentially).
- Shared resolution, inheritance, caching, compilation or a broad refactor: `composer test-all` (format check, Psalm, PHPStan, PHPUnit).
- Coverage: `composer test-coverage-check`. Line floor 95%; PHP 8.4+ covers native lazy objects.
- Mutation-sensitive logic: Infection. MSI floors are 87%; never lower them.
- Performance: read `tools/bench-compare.sh` first (it temporarily switches `src` and `benchmarks`), then `composer bench:compare -- origin/main`. Record baseline commit, PHP version, opcache/JIT settings and mode; recheck anomalous results. CI benchmarks are required.
