# 6. Migration from 0.1 to 0.9

Date: 2026-10-06
Status: Accepted; deprecations removed in 1.0.0 (2026-10-09)

## Context

ADRs 2–5 break v0.1 behaviour. Upgraders need a predictable path, and 1.0 must start without legacy baggage.

## Decision

- **Version:** the next breaking release is **0.9.0**, with no 0.2. Composer and npm stay in lockstep. 0.1.1 is the last 0.1 release.
- **Deprecated, still working in 0.9, removed in 1.0:**
  - `new DatatableApi` + `fromQuery()`;
  - `withCustomFilters()`.

  Both carry `@deprecated` and emit `E_USER_DEPRECATED` through `trigger_error()`, which Laravel routes to the `deprecations` log channel.
- **Hard breaks in 0.9:**
  - `DatatableApi` is `final`;
  - every `with*` method replaces instead of accumulating;
  - `auto_discover_columns` defaults to `false`; setting it back to `true` restores discovery;
  - `sort_by` is ignored without `withSortableColumns()`. There is no legacy switch, because free sorting leaks the order of hidden values.
- **Docs:** `UPGRADE.md` at the repository root holds one section per breaking change, with before/after code. `CHANGELOG.md` links to it.

## Consequences

- An upgrader with declared search and sort columns only has to rename the entry point, and even that can wait until 1.0.
- An upgrader who relied on free sorting must declare it. The warning in the log names the dropped key.
