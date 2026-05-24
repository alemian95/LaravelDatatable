# Changelog

All notable changes to `LaravelDatatable` will be documented in this file.

## Unreleased

### Added

- Laravel 13 support: `illuminate/contracts` widened to `^11.0||^12.0||^13.0` and `orchestra/testbench` (dev) widened to `^11.0.0||^10.0.0||^9.0.0`. CI matrix now also exercises Laravel 13 paired with Testbench 11. Laravel 11 and 12 remain fully supported.
- `DatatableApi::withRelationSearch(array)` and the `RelationSearch` DSL (`belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, `custom`) for declaring how to resolve dot-notation search columns. Required on raw `QueryBuilder`; optional override on Eloquent.

### Changed

- Eloquent single-hop dot-notation search now emits `orWhereExists(...)` with explicit key joins instead of `orWhereHas(...)`. Same row set, no eager-loading side effect; the SQL text in logs changes.
