# @alemian95/laraveldatatable-react

React datatable for the [`alemian95/laraveldatatable`](https://packagist.org/packages/alemian95/laraveldatatable)
Laravel package. It drives the backend's server-side contract — search, sort, pagination —
and adds column visibility, a filters slide-over, and bulk actions over selected rows.

Self-contained: it bundles its own shadcn-look UI (Radix + Tailwind), so it does **not**
depend on your project's `@/components/ui/*`. You only need Tailwind.

## Install

```bash
npm install @alemian95/laraveldatatable-react
```

### Peer dependencies

Only these — every React app already has them:

- `react`, `react-dom` (^18 or ^19)
- `tailwindcss` (^3 or ^4)

Everything else (`@tanstack/react-query`, `@tanstack/react-table`, Radix) is a regular
dependency and gets installed automatically. You do **not** need to install or configure
`@tanstack/react-query` yourself — `DatatableProvider` owns an internal `QueryClient`. To
share your app's client instead, pass it: `<DatatableProvider queryClient={yourClient} …>`.

### Tailwind

The components ship pre-written utility classes. Add the package's `dist` to your Tailwind
`content` so those classes are generated:

```js
// tailwind.config.js
export default {
  content: [
    './src/**/*.{ts,tsx}',
    './node_modules/@alemian95/laraveldatatable-react/dist/**/*.js',
  ],
}
```

## Usage

```tsx
import {
  DatatableProvider, DataTable,
  type ColumnDef, type FilterDef, type BulkAction,
} from '@alemian95/laraveldatatable-react'

type User = { id: number; name: string; email: string; created_at: string }

const columns: ColumnDef<User, unknown>[] = [
  { accessorKey: 'name', header: 'Name', enableSorting: true, meta: { searchable: true } },
  { accessorKey: 'email', header: 'Email', meta: { searchable: true } },
  { accessorKey: 'created_at', header: 'Created', enableSorting: true, meta: { sortKey: 'created_at' } },
]

const filters: FilterDef[] = [
  { id: 'status', label: 'Status', type: 'select', options: [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
  ] },
  { id: 'created_at', label: 'Created', type: 'date-range' },
]

const bulkActions: BulkAction<User>[] = [
  { value: 'delete', label: 'Delete', handler: (rows) => console.log('delete', rows) },
]

export function Users() {
  return (
    <DatatableProvider config={{
      baseUrl: '/api',
      // Static object, or a (possibly async) function so tokens can refresh.
      headers: async () => ({ Authorization: `Bearer ${await getToken()}` }),
    }}>
      <DataTable<User>
        endpoint="/users"
        columns={columns}
        defaultPerPage={15}
        filters={filters}
        bulkActions={bulkActions}
      />
    </DatatableProvider>
  )
}
```

## API

### `DatatableProvider`

```ts
interface DatatableConfig {
  baseUrl: string           // prepended to each DataTable endpoint
  headers?: HeadersInit | (() => HeadersInit | Promise<HeadersInit>)
}
```

The provider owns an internal `QueryClient` by default. Pass `queryClient` to share your
app's own client with the tables.

### `DataTable`

```ts
interface DataTableProps<T> {
  endpoint: string                        // appended to config.baseUrl
  columns: ColumnDef<T, unknown>[]        // TanStack column defs + optional meta
  defaultPerPage?: number                 // default 15
  filters?: FilterDef[]                   // renders the Filters slide-over
  bulkActions?: BulkAction<T>[]           // enables row selection + the bulk bar
  getRowId?: (row: T, i: number) => string // stable row identity (defaults to row.id)
}
```

Column `meta` extension:

```ts
interface ColumnMeta {
  searchable?: boolean   // include this column id in search_columns
  sortKey?: string       // sort_by value to send (defaults to the column id)
}
```

### `useDatatable`

The hook behind `DataTable`, exported for custom UIs. Returns
`{ rows, pageCount, total, isLoading, isFetching, error, refetch }`.

## Request contract

`DataTable` emits these query params (1:1 with the backend `DatatableRequest`):

| Param                                     | Source                                    |
|-------------------------------------------|-------------------------------------------|
| `page` (1-based)                          | pagination                                |
| `per_page`                                | rows-per-page select / `defaultPerPage`   |
| `search`                                  | search box (debounced)                    |
| `search_columns` (csv)                    | visible columns with `meta.searchable`    |
| `sort_by`, `sort_order` (`asc`\|`desc`)   | header sort (`meta.sortKey` ?? column id) |
| `filter[<id>]` / `filter[<id>][from\|to]` | filters slide-over                        |

The expected response is a Laravel length-aware paginator
(`data`, `current_page`, `last_page`, `per_page`, `total`).

### Filters are emitted, not applied

The package **sends** `filter[...]` params but does not know how to apply them server-side.
Read them in your controller and translate them into `withCustomFilters(...)` closures on the
`DatatableApi`, e.g.:

```php
->withCustomFilters([
    fn ($q) => request('filter.status') ? $q->where('status', request('filter.status')) : $q,
])
```

### Sorting is whitelisted server-side

The backend enforces a sort whitelist via `DatatableApi::withSortableColumns(...)`. Only
expose sortable columns (`enableSorting: true`) that the backend actually allows, otherwise
the sort is dropped server-side with a warning.
