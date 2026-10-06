# 5. Secure defaults when nothing is declared

Date: 2026-10-06
Status: Accepted

## Context

- **S1:** `auto_discover_columns` is on by default. It allows `LIKE` probing of any text column, including ones the API never exposes.
- **S2:** with no `withSortableColumns`, any column can be sorted, which leaks the order of hidden values.

## Decision

- **Search:** `auto_discover_columns` defaults to `false` and stays available as an explicit opt-in. A search request on a table with no declared searchable columns throws `SearchColumnsNotConfiguredException`, the existing behaviour when discovery is off.
- **Sort:** without `withSortableColumns`, `sort_by` is ignored with a log warning. Only `withCustomSorts` keys apply.
- **Unknown keys:** a `sort_by`, `search_columns` entry or `filter[...]` key outside the declared whitelist is ignored with a log warning, never answered with a 400 or 422. A stale frontend keeps working.

## Consequences

- Upgraders who relied on auto-discovery or free sorting must declare columns, or re-enable discovery in config. Both are covered in UPGRADE.
- Misconfigurations surface in the logs rather than as client errors.
