# 3. Entry point, execution and `with*` semantics

Date: 2026-10-06
Status: Accepted

## Context

- **A1:** the constructor reads the global `request()`, so the class cannot be used outside HTTP or tested with an explicit request.
- **A2:** the query runs inside `jsonSerialize()` and mutates the builder, so serializing twice applies search and sort twice.
- **A3:** `withCustomFilters` accumulates, while `withCustomSorts` and `withRelationSearch` replace.

## Decision

- **Entry point:** `DatatableApi::for(Builder $query, ?Request $request = null)`. Without an explicit request it uses the container's current request at call time. `new DatatableApi` + `fromQuery()` remain in 0.9 as deprecated and are removed in 1.0.
- **Execution:**
  - `DatatableApi` implements `Responsable`, and `toPaginator()` is public: it returns the paginator, or the resource collection when `returnResource()` is set;
  - `jsonSerialize()` delegates to `toPaginator()`;
  - each execution works on a clone of the builder, so it is idempotent.
- **`with*` methods:** every one replaces. The last call wins.

## Consequences

- Controllers can `return $api;`. Inertia pages can pass `$api->toPaginator()` as a prop.
- Calling `withCustomFilters` twice used to combine the closures. That is a breaking change, listed in UPGRADE.
