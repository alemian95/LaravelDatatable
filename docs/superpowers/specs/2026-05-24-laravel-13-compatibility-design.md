# Laravel 13 Compatibility

**Date:** 2026-05-24
**Status:** Approved (design phase)
**Scope:** Extend the `alemian95/laraveldatatable` package's supported Laravel range to include Laravel 13, while preserving full compatibility with Laravel 11 and Laravel 12.

---

## 1. Goal

Allow consumers running Laravel 13 to install and use the package without forcing a downgrade, and run the existing test suite against Laravel 13 in CI so future regressions are caught.

Non-goals:
- No new features.
- No refactor of existing source code.
- No drop of Laravel 11 or 12 support.
- No PHP version bump (already `^8.4`, which satisfies Laravel 13's `^8.3` requirement).

## 2. Context

Current constraints (from `composer.json`):

- `illuminate/contracts: ^11.0||^12.0`
- `orchestra/testbench (dev): ^10.0.0||^9.0.0`
- `php: ^8.4`

Laravel 13 (released February 2026) requires PHP `^8.3` and Symfony `^7.4 || ^8.0`. The matching Testbench major is `^11.0` (latest at the time of writing: `v11.1.0`).

The package source uses only stable, long-standing Laravel surfaces:
- `Illuminate\Http\Request` (in `DatatableRequest`)
- `Illuminate\Console\Command` (in `DatatableCommand`)
- Eloquent query builder via the standard `\Illuminate\Database\Eloquent\Builder` API
- `spatie/laravel-package-tools` for service provider scaffolding

No known Laravel 13 breaking change touches these surfaces.

## 3. Changes

### 3.1 `composer.json`

Widen the production constraint:

```json
"illuminate/contracts": "^11.0||^12.0||^13.0"
```

Widen the dev testbench constraint:

```json
"orchestra/testbench": "^11.0.0||^10.0.0||^9.0.0"
```

PHP stays at `^8.4`. Other dev dependencies (`larastan/larastan ^3.0`, `pestphp/pest ^4.0`, `pestphp/pest-plugin-laravel ^4.0`, `nunomaduro/collision ^8.8`, `phpstan/phpstan-*`) are expected to resolve against Laravel 13 with current constraints; if `composer update --prefer-stable` against L13 reports conflicts, the conflicting dev dep gets a targeted bump — recorded in the implementation plan.

### 3.2 CI workflow `.github/workflows/run-tests.yml`

Extend the matrix to include Laravel 13:

```yaml
matrix:
  os: [ubuntu-latest, windows-latest]
  php: [8.4]
  laravel: [13.*, 12.*, 11.*]
  stability: [prefer-lowest, prefer-stable]
  include:
    - laravel: 13.*
      testbench: 11.*
    - laravel: 12.*
      testbench: 10.*
    - laravel: 11.*
      testbench: 9.*
```

The rest of the workflow is unchanged. Existing constraints on `php: [8.4]` and `prefer-lowest`/`prefer-stable` both apply uniformly to the new Laravel row.

### 3.3 Source code

No changes expected. If running the test suite against Laravel 13 surfaces a deprecation warning or behavioral break (e.g. a Carbon 3 API change leaking into the package, a new `Request` method signature), apply the minimum targeted fix and document it in the implementation plan as a discovered task — do not expand the scope into unrelated cleanup.

## 4. Verification

Run locally before opening the PR:

```bash
# Snapshot current lock
cp composer.lock composer.lock.bak

# Force Laravel 13 + Testbench 11 resolution
composer require \
  "laravel/framework:^13.0" \
  "orchestra/testbench:^11.0" \
  --no-interaction --no-update --dev
composer update --prefer-stable --no-interaction

# Test suite must pass green
vendor/bin/pest

# Static analysis must stay clean
vendor/bin/phpstan analyse

# Restore the committed lock (we don't ship a L13-locked composer.lock)
mv composer.lock.bak composer.lock
```

Pass criteria:
- `vendor/bin/pest` exits 0 against Laravel 13.
- `vendor/bin/phpstan analyse` reports no new errors against Laravel 13.
- The existing L11 + L12 matrix entries continue to pass in CI.

## 5. Risks

- **Transitive conflict on a dev dep.** Mitigation: targeted bump of the offending dep with the same `||` widening style. If a bump introduces a breaking change in that dep, escalate to user before committing.
- **Hidden deprecation in `Illuminate\Http\Request` or `Console\Command` between L12 and L13.** Mitigation: caught by the CI matrix and by local `vendor/bin/pest` run before PR.
- **`composer.lock` drift.** Mitigation: do not commit a Laravel 13-resolved `composer.lock`; keep the L12-resolved lock that ships today. CI installs L13 on the fly via `composer require`.

## 6. Out of scope

- Bumping PHP minimum.
- Dropping Laravel 11.
- Migrating to a different testing tool.
- Restructuring CI beyond adding the L13 row.
- Refactoring source code beyond what a Laravel 13 incompatibility (if any surfaces) strictly requires.
