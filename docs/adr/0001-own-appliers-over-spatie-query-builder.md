# 1. Keep our own appliers instead of building on spatie/laravel-query-builder

Date: 2026-10-06
Status: Accepted

## Context

v0.9 fixes the public API ahead of 1.0. The research for [#21](https://github.com/alemian95/LaravelDatatable/issues/21) (`docs/research/peer-filter-models.md`) found that spatie/laravel-query-builder already offers whitelisted filters and sorts and returns 400 on unknown keys. It is Eloquent-only, though. This package supports raw `DB::table()` queries today, including relation search through `RelationSearch`.

## Decision

Search, sort and filtering stay on our own appliers. spatie is not a dependency.

## Consequences

- Raw QueryBuilder support stays, along with control over the HTTP contract and the error policy (see ADR 5).
- Consumers get no extra dependency.
- We maintain filtering ourselves. The declarative filters of C1 are 1.x work built on ADR 4.
