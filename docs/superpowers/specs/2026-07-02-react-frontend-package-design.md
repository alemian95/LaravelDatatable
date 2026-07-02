# React frontend package — design

**Date:** 2026-07-02
**Status:** approved (design), pending spec review
**Scope:** add a companion React package to the existing `alemian95/laraveldatatable` Composer package.

## Overview

Ship a standalone npm package, `@alemian95/laraveldatatable-react`, that renders a
server-side datatable talking to the backend's existing HTTP contract. It provides a
shadcn-styled table (TanStack Table), server-driven data fetching (TanStack Query with
configurable auth headers), developer-configured columns, runtime column visibility, a
slide-over filters panel, and a bulk-actions bar over selected rows.

The package is self-contained: it bundles its own shadcn-look UI primitives (Radix +
Tailwind classes) so it does not import the consumer's `@/components/ui/*`. The consumer
installs it with `npm install @alemian95/laraveldatatable-react`.

## Goals

- One `<DataTable>` component that covers search, sort, pagination, column visibility,
  extra filters, and bulk actions against the backend contract, server-side.
- Configurable auth headers (static object or sync/async function) so tokens can refresh.
- Developer-configured columns via TanStack `ColumnDef` plus a small `meta` extension.
- Look identical to shadcn without depending on the consumer's shadcn install.

## Non-goals (explicitly out of scope for v1)

- No artisan/install command: delivery is `npm install`, nothing to scaffold server-side.
- No Inertia/SSR adapter, no row virtualization, no built-in CSV export.
- No backend generic filter parser. The package **emits** filter query params; applying
  them server-side is the consumer's job (read params in the controller → `withCustomFilters`),
  or a future backend PR. See "Server contract → Filters".
- No client-side (in-memory) mode. Everything is server-side (`manual*` = true).

## Package layout (monorepo, lightweight)

New `react/` directory in this repo, published independently to npm.

```
react/
  package.json            # name: @alemian95/laraveldatatable-react
  tsconfig.json
  tsup.config.ts          # bundle to ESM + types
  vitest.config.ts
  src/
    index.ts              # public exports
    provider.tsx          # DatatableProvider + context
    use-datatable.ts      # TanStack Query hook: state -> params -> fetch -> rows
    data-table.tsx        # the table shell (toolbar + table + pagination)
    toolbar.tsx           # search, bulk-actions bar, Filters + Columns buttons
    filters-sheet.tsx     # slide-over filters panel
    types.ts              # ColumnMeta, DatatableConfig, FilterDef, BulkAction, PaginatorResponse
    ui/                   # bundled shadcn-look primitives (Radix + Tailwind)
      table.tsx button.tsx input.tsx select.tsx checkbox.tsx
      dropdown-menu.tsx sheet.tsx badge.tsx
  __tests__/
    use-datatable.test.ts # param-mapping (the non-trivial logic)
    data-table.test.tsx   # render smoke + selection -> bulk bar
```

## Dependencies

- **peer** (consumer provides): `react`, `react-dom`, `@tanstack/react-query`, `tailwindcss`.
- **bundled** (our deps): `@tanstack/react-table`, `@radix-ui/react-dropdown-menu`,
  `@radix-ui/react-checkbox`, `@radix-ui/react-dialog` (sheet), `@radix-ui/react-select`,
  `clsx`. Tailwind classes are emitted as plain strings; the consumer's Tailwind build
  picks them up (documented `content` glob addition in the README).

## Public API

Three exports: `DatatableProvider`, `DataTable`, `useDatatable` (+ types).

```tsx
import { DatatableProvider, DataTable } from '@alemian95/laraveldatatable-react'

<DatatableProvider config={{
  baseUrl: '/api',
  headers: async () => ({ Authorization: `Bearer ${await getToken()}` }),
}}>
  <DataTable
    endpoint="/users"
    columns={columns}
    defaultPerPage={15}
    filters={filters}
    bulkActions={bulkActions}
  />
</DatatableProvider>
```

### DatatableConfig (provider)

```ts
type HeadersResolver = HeadersInit | (() => HeadersInit | Promise<HeadersInit>)

interface DatatableConfig {
  baseUrl: string           // prepended to each DataTable's endpoint
  headers?: HeadersResolver // resolved per request; async supported for token refresh
}
```

The provider stores config in React context. It does NOT create a `QueryClient` — the
consumer already wraps their app in `QueryClientProvider`. If none is found, the hook
throws a clear error pointing to the setup docs.

### Columns

Standard TanStack `ColumnDef<T>` with a typed `meta` extension:

```ts
interface ColumnMeta {
  searchable?: boolean  // include this column's id in search_columns
  sortKey?: string      // override the sort_by value (defaults to column id / accessorKey)
}
```

`enableSorting` (native TanStack flag) drives whether the header is clickable. Column
visibility uses TanStack's native `columnVisibility` state, toggled from the Columns
dropdown.

### FilterDef (filters sheet)

```ts
type FilterDef =
  | { id: string; label: string; type: 'select'; options: { value: string; label: string }[] }
  | { id: string; label: string; type: 'date-range' }
  | { id: string; label: string; type: 'text' }
```

Rendered inside the slide-over Sheet. Values are collected into a `filters` state object
and emitted as query params (see below). A count badge on the Filters button reflects the
number of active (non-empty) filters. Reset clears all.

### BulkAction (bulk-actions bar)

```ts
interface BulkAction<T> {
  value: string
  label: string
  handler: (selectedRows: T[]) => void | Promise<void>
}
```

Passing a non-empty `bulkActions` array enables the row-selection checkbox column. When
≥1 row is selected, the toolbar shows "N selected", a Select of the actions, and an Apply
button that calls the chosen action's `handler` with the selected row objects. After an
async handler resolves, the query is invalidated (refetch) and the selection is cleared.

## Server contract (maps 1:1 onto DatatableRequest)

`useDatatable` builds the query string from component state:

| State                | Query param                              | Backend field       |
|----------------------|------------------------------------------|---------------------|
| page                 | `page`                                   | Laravel paginator   |
| perPage              | `per_page`                               | `perPage`           |
| search               | `search`                                 | `search`            |
| searchable columns   | `search_columns` (csv)                   | `searchColumns`     |
| sorting[0]           | `sort_by`, `sort_order`                  | `sortBy`, `sortOrder` |
| filters              | `filter[<id>]` / `filter[<id>][from\|to]`| consumer-applied    |

Only the first sort is sent (backend sorts by a single column). `sort_by` uses
`meta.sortKey` when present, else the column id. Because the backend now enforces a sort
whitelist (`withSortableColumns`), the client should only expose sortable columns the
backend allows — documented in the README.

### Response

Laravel length-aware paginator JSON:

```ts
interface PaginatorResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}
```

The hook returns `{ rows, pageCount: last_page, total, isLoading, isFetching, error }`.
`DataTable` runs TanStack Table with `manualPagination`, `manualSorting`,
`manualFiltering` = true and `pageCount` from the response.

### Filters — backend note

The package emits `filter[...]` params but does not apply them server-side. The consumer
reads them in their controller and translates them into `withCustomFilters(...)` closures.
This keeps the frontend package decoupled and avoids a premature generic filter DSL on the
backend. A future backend PR may add a declarative filter parser to `DatatableRequest`; it
would consume the same `filter[...]` convention, so the frontend would not change.

## Data flow

1. `DataTable` holds state: `pagination {pageIndex, pageSize}`, `sorting`, `search` (debounced ~300ms), `columnVisibility`, `filters`, `rowSelection`.
2. `useDatatable(endpoint, { pagination, sorting, search, searchColumns, filters })` derives the query string, resolves headers from context (awaiting if a function), and `fetch`es `${baseUrl}${endpoint}?...`.
3. TanStack Query keys on `[endpoint, params]` with `placeholderData: keepPreviousData` so pagination/sort changes don't flash empty.
4. Response feeds TanStack Table (manual mode). Header clicks mutate `sorting`; pagination controls mutate `pagination`; the search box mutates `search`; the sheet mutates `filters`; checkboxes mutate `rowSelection`.

## States

- **Loading (first load):** skeleton rows.
- **Fetching (background refetch):** subtle top progress / reduced opacity, previous data visible (keepPreviousData).
- **Error:** an inline error row with a Retry button that refetches; the raw exception is not shown.
- **Empty:** an empty-state row ("No results.").

## Testing

Vitest + React Testing Library. Two focused files:

1. `use-datatable.test.ts` — the non-trivial logic: state → query string mapping. Covers
   search + searchable columns → `search`/`search_columns`, sorting → `sort_by`/`sort_order`
   with `meta.sortKey`, pagination → `page`/`per_page`, filters → `filter[...]` (including
   date-range and empty-filter omission), and async header resolution.
2. `data-table.test.tsx` — render smoke test with a mocked fetch; selecting a row reveals
   the bulk-actions bar; applying an action calls the handler with the selected rows.

No E2E, no framework beyond Vitest/RTL.

## Build & CI

- Build with `tsup` → ESM + `.d.ts`. `sideEffects: false` for tree-shaking.
- Add a GitHub Actions job (Node) running `npm ci && npm run build && npm test` scoped to
  `react/**` changes (path filter), parallel to the existing PHP jobs. The PHP workflows
  keep their PHP-only path filters so they don't run on `react/**`-only changes.

## Decisions taken during brainstorming

- Delivery = npm package (not artisan publish, not shadcn copy-paste).
- shadcn look = self-contained (Radix + Tailwind bundled), Tailwind as a peer dep.
- Repo layout = `react/` folder in this repo (single source of truth, independent release).
- Auth headers = static object OR sync/async function.
- Filters live client-side and are emitted as `filter[...]` params; backend wiring is the
  consumer's responsibility for v1.
