# Changelog

All notable changes to `LaravelDatatable` will be documented in this file.

## Unreleased

### Changed

- **Support floor raised**: minimum PHP is now `^8.3` and minimum Laravel is `^12.0` (Laravel 11 dropped). Laravel 12 and 13 on PHP 8.3 and 8.4 are covered by CI. Existing code is unaffected — the package already used no PHP 8.4-only syntax.

### Fixed

- README examples now use `(new DatatableApi())->...` instead of the PHP 8.4-only `new DatatableApi()->...` form, so they parse on PHP 8.3.

## v0.0.3 - 2026-07-03

### Security

- `SortApplier` no longer invokes arbitrary model methods from a dot-notation `sort_by`. Dot-notation sort now requires an explicit whitelist (`DatatableApi::withSortableColumns(...)`); without one it is dropped with a warning instead of calling a client-named method (which could run side effects such as `save()`/`delete()`). Whitelisted relation segments are resolved with `try/catch` + `instanceof Relation`, mirroring the search side.
- `sort_order` is now validated against `asc|desc` (case-insensitive, defaults to `asc`). Previously an arbitrary value reached `orderBy()` (500 on bad input) and was passed unsanitized to custom-sort closures that interpolate it into `orderByRaw()`.

### Fixed

- `search` and `sort_by` supplied as arrays (`?search[]=a`) no longer raise a `TypeError`; they are coerced to `null`.
- An unresolvable dot-notation `sort_by` no longer emits broken SQL (`order by "a"."b"` on a missing alias); it is dropped with a warning.

### Changed

- React package `@alemian95/laraveldatatable-react` → `0.0.3`. Fixes from a code review:
  - **Row selection is now identity-based** (`getRowId`, default `row.id`) and cleared on page/search/filter change — bulk actions no longer target the wrong records after paginating.
  - **The table resets to page 1** when the search term or filters change (no more empty page beyond the last).
  - `ColumnMeta` is now type-safe via module augmentation — a typo in `meta` is a compile error, not a silent no-op.
  - Sortable headers are keyboard-accessible `<button>`s with `aria-sort`.
  - Bulk actions guard against double-submit and swallowed errors; filter selects can be cleared back to "Any"; a `'use client'` banner is emitted for the Next.js App Router; `baseUrl` is part of the query key; minor a11y and packaging fixes.

## v0.0.2 - 2026-07-03

### Added

- `datatable:install` artisan command: installs the `@alemian95/laraveldatatable-react` npm package (detecting npm/pnpm/yarn/bun), publishes the config, warns about missing peer dependencies and prints the setup steps.

### Changed

- Companion React package `@alemian95/laraveldatatable-react` reaches `0.0.2`: `@tanstack/react-query` moved from a peer to a regular dependency (installed automatically), and `DatatableProvider` now owns an internal `QueryClient`, so consumers no longer set up `@tanstack/react-query` themselves.

## v0.0.1 - 2026-07-02

First tagged release.

### Added

- Companion React package `@alemian95/laraveldatatable-react` (in `/react`, published separately to npm): server-side datatable with search, sort, pagination, column visibility, filters slide-over and bulk actions.
- Laravel 13 support (alongside 11 and 12).
- `DatatableApi::withRelationSearch(...)` + the `RelationSearch` DSL for dot-notation relational search.
- `DatatableApi::withSortableColumns(...)` — sort whitelist for the `sort_by` param.
- `default.max_per_page` config (clamps `per_page`); `debug.log_sql` config (gates the per-query SQL log, off by default).

### Changed

- Single-hop dot-notation search emits `orWhereExists(...)` instead of `orWhereHas(...)`.
- SQL is no longer logged on every non-production request (now gated by `debug.log_sql`).
- `per_page` clamped to `[1, max_per_page]`.

### Removed

- Dead `withRelationshipsAutoloading` branch.

**Composer:** `composer require alemian95/laraveldatatable`
**npm (frontend):** `npm install @alemian95/laraveldatatable-react`

## 0.0.1 - 2026-07-02

First tagged release.

### Added

- Companion React package `@alemian95/laraveldatatable-react` (in `/react`, published separately to npm): server-side datatable with search, sort, pagination, column visibility, a filters slide-over and bulk actions, driving this package's HTTP contract.
- Laravel 13 support: `illuminate/contracts` widened to `^11.0||^12.0||^13.0` and `orchestra/testbench` (dev) widened to `^11.0.0||^10.0.0||^9.0.0`. CI matrix now also exercises Laravel 13 paired with Testbench 11. Laravel 11 and 12 remain fully supported.
- `DatatableApi::withRelationSearch(array)` and the `RelationSearch` DSL (`belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, `custom`) for declaring how to resolve dot-notation search columns. Required on raw `QueryBuilder`; optional override on Eloquent.
- `DatatableApi::withSortableColumns(array)` — authoritative whitelist for the `sort_by` request parameter. A `sort_by` outside the list is dropped with a warning instead of reaching the database. Custom sort keys declared via `withCustomSorts()` are always allowed. Leaving it unset preserves the previous behavior of sorting by any client-supplied column.
- `default.max_per_page` config key (default `100`): hard upper bound the incoming `per_page` is clamped to, preventing a client from requesting an unbounded page size.
- `debug.log_sql` config key (default `false`): gate for the per-query SQL log.

### Changed

- Eloquent single-hop dot-notation search now emits `orWhereExists(...)` with explicit key joins instead of `orWhereHas(...)`. Same row set, no eager-loading side effect; the SQL text in logs changes.
- The generated SQL is no longer logged on every non-production request. Logging is now off by default and controlled by the new `debug.log_sql` config flag (the interpolated SQL could contain the raw search term).
- `per_page` is now clamped to `[1, max_per_page]`. Requests above the cap receive `max_per_page` rows instead of the requested amount; raise `default.max_per_page` if a legitimate caller needs more.

### Removed

- Dead `withRelationshipsAutoloading` branch in `DatatableApi::jsonSerialize()`: it had no public setter (always disabled) and ran after `paginate()` had already executed, so it could never take effect.
