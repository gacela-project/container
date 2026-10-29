---
name: container-php
description: PHP conventions for Gacela Container source and tests.
globs: ["src/**", "tests/**", "benchmarks/**"]
---

Use `declare(strict_types=1)` and match sibling code. Source uses `Gacela\Container`; tests use `GacelaTest` and benchmarks use `GacelaBench` via Composer's PSR-4 mappings.

Keep resolution decisions with the registry, plan, or resolver that owns the data. Reuse existing fixtures under `tests/Fake/`. Trace runtime resolution, validation, and compiled factories when changing wiring so they keep the same observable behaviour.

For behaviour changes, add a focused regression test that fails before the fix. Prefer observable results over reflection into internals. Assert exception classes; exception text is outside the compatibility promise. Test PHP 8.3 and 8.4 paths when lazy-object behaviour changes.
