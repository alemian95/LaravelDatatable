# 5. Secure defaults when nothing is declared

Date: 2026-10-06
Status: Accepted, amended 2026-10-08

## Context

- **S1:** `auto_discover_columns` is on by default. It allows `LIKE` probing of any text column, including ones the API never exposes.
- **S2:** with no `withSortableColumns`, any column can be sorted, which leaks the order of hidden values.

## Decision

- **Search:** `auto_discover_columns` defaults to `false` and stays available as an explicit opt-in. A search request on a table with no declared searchable columns is ignored with a log warning, like an undeclared sort or filter.
  - *Amended 2026-10-08 (0.9.1):* it used to throw `SearchColumnsNotConfiguredException`. In a real app the React table always shows a search box, so a table without declared columns answered 500 at the first keystroke. The resolver contract still throws the exception; `SearchApplier` turns it into the warning.
- **Sort:** without `withSortableColumns`, `sort_by` is ignored with a log warning. Only `withCustomSorts` keys apply.
- **Unknown keys:** a `sort_by`, `search_columns` entry or `filter[...]` key outside the declared whitelist is ignored with a log warning, never answered with a 400 or 422. A stale frontend keeps working.

## Consequences

- Upgraders who relied on auto-discovery or free sorting must declare columns, or re-enable discovery in config. Both are covered in UPGRADE.
- Misconfigurations surface in the logs rather than as client errors.
- **Filter values** follow the same rule: a value whose shape the declared closure does not take (a `{from, to}` range for a closure typed `string $value`, or the reverse) is ignored with a warning instead of raising a `TypeError`.
