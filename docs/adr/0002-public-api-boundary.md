# 2. Public API boundary for 0.9 / 1.0

Date: 2026-10-06
Status: Accepted

## Context

Semver needs a stated surface. Today every class is public and non-final, so any refactor could break a consumer.

## Decision

These are public and covered by semver:

- **Backend**
  - `DatatableApi`, which becomes `final`;
  - `Search\RelationSearch`;
  - the `Contracts\HasSearchableColumns` contract and the `Concerns\HasSearchableColumns` trait;
  - `Exceptions\SearchColumnsNotConfiguredException`;
  - the `config/laraveldatatable.php` keys;
  - the `datatable:install` command.
- **HTTP**
  - query parameters `page`, `per_page`, `search`, `search_columns`, `sort_by`, `sort_order` and `filter[<key>]`. A `filter[<key>]` value is a scalar or `{from, to}`;
  - two response envelopes: the Laravel length-aware paginator, and an API Resource collection with `meta`.
- **React**: every export of `react/src/index.ts`.

Everything else is marked `@internal` and may change in any release: appliers, resolvers, `Search\Sources\*`, `Support\*`, `DatatableRequest` and `Contracts\QueryApplier`, plus the resolver contracts.

## Consequences

- Shared configuration now goes through composition, a function or a factory that returns `DatatableApi`, instead of subclassing.
- Container bindings for the resolver contracts keep working, but they are not a supported extension point.
