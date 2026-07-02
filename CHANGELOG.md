# Changelog

All notable changes to `LaravelDatatable` will be documented in this file.

## Unreleased

### Added

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
