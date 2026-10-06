# 4. Keyed client filters

Date: 2026-10-06
Status: Accepted

## Context

The React table sends `filter[<key>]=value` (and `filter[<key>][from|to]` for ranges). On the backend, `withCustomFilters` closures receive only the builder, so they read `request()` themselves. That contradicts ADR 3 and leaves the filter contract unenforced.

## Decision

- New `withFilters(array<string, Closure(Builder, mixed): void>)`.
- A closure runs only when `filter[<key>]` is present and non-empty. It receives the value already parsed from the request: a string, or `['from' => ?string, 'to' => ?string]`.
- Undeclared keys follow ADR 5.
- `withCustomFilters` is deprecated in 0.9 and removed in 1.0. Fixed server-side constraints (tenant, active scope) belong on the query passed to `for()`.

## Consequences

- The `filter[...]` wire format is fixed now.
- Declarative helpers such as `Filter::exact()` and `Filter::dateRange()` (C1) can come in 1.x as closures, or as objects built on this, without breaking anything.
