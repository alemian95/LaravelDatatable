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

> **Using pnpm (or another strict `node_modules`)?** Those layouts don't expose a library's
> transitive dependencies to your own code. As long as you import everything from
> `@alemian95/laraveldatatable-react` — including `ColumnDef`, which this package re-exports
> (see the example below) — you don't need to add anything. But if you import
> `@tanstack/react-table` or `@tanstack/react-query` **directly** in your own files, declare
> them yourself: `pnpm add @tanstack/react-table @tanstack/react-query`. With npm or Yarn the
> transitive packages are hoisted, so direct imports resolve without this step.

### Tailwind

The components ship pre-written utility classes, so Tailwind must scan the package's `dist`
to generate them. Tailwind ignores `node_modules` by default — point it at the package
explicitly, matching your Tailwind major version.

**Tailwind v4** (CSS-first config — there is no `tailwind.config.js`). Add a `@source` next
to your import:

```css
@import "tailwindcss";
@source "../../node_modules/@alemian95/laraveldatatable-react/dist";
```

The `@source` path is resolved relative to the CSS file. Adjust the `../` depth so it points
at your project's `node_modules` — e.g. from `resources/css/app.css` in a Laravel app it is
`../../node_modules/...`.

**Tailwind v3** (`tailwind.config.js`). Add the package to `content`:

```js
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
  labels?: Partial<DatatableLabels>  // texts shown by the tables
}
```

#### Labels

Every text the tables show comes from `labels`. Pass only the keys you want to change; the
rest keep the English defaults (exported as `defaultLabels`). Counts and pages are functions:

```tsx
<DatatableProvider config={{
  baseUrl: '',
  labels: {
    search: 'Cerca…',
    filters: 'Filtri',
    columns: 'Colonne',
    any: 'Tutti',
    from: 'dal',
    to: 'al',
    reset: 'Azzera',
    applyFilters: 'Applica filtri',
    selected: (n) => `${n} selezionati`,
    actions: 'Azioni…',
    apply: 'Applica',
    loading: 'Caricamento…',
    error: 'Impossibile caricare i dati.',
    retry: 'Riprova',
    noResults: 'Nessun risultato.',
    total: (n) => `${n} risultati`,
    page: (page, pages) => `Pagina ${page} di ${pages}`,
    previous: 'Precedente',
    next: 'Successiva',
  },
}}>
```

See the `DatatableLabels` type for every key, including the accessible names
(`selectAll`, `selectRow`, `bulkAction`) and `searchDisabled`, `filtersDescription`, `applying`.

The provider owns an internal `QueryClient` by default. Pass `queryClient` to share your
app's own client with the tables.

### `DataTable`

```ts
interface DataTableProps<T> {
  endpoint: string                        // appended to config.baseUrl
  columns: ColumnDef<T, unknown>[]        // TanStack column defs + optional meta
  defaultPerPage?: number                 // default 15
  perPageOptions?: number[]               // default [15, 25, 50]; defaultPerPage is always added.
                                          // Keep them ≤ the backend's max_per_page (it clamps silently)
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
  label?: string         // name in the Columns menu (defaults to a string header, then the id)
}
```

Search targets the *visible* columns with `meta.searchable`. Hiding all of them disables the
search box. If no column sets `meta.searchable`, `search_columns` is not sent and the backend's
whitelist decides.

### `useDatatable`

The hook behind `DataTable`, exported for custom UIs. Returns
`{ rows, pageCount, total, isLoading, isFetching, error, refetch }`.

Only network failures are retried. An HTTP error is reported at once, with the
status and the server's `message` in `error.message` (e.g. `Request failed with status 500: Server Error`).

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

The expected response is either a Laravel length-aware paginator
(`data`, `current_page`, `last_page`, `per_page`, `total`) or the `{ data, links, meta }`
envelope produced by `returnResource(...)` — both are read transparently.

### Filters are applied by `withFilters()`

The table sends `filter[<id>]=value` (and `filter[<id>][from]` / `[to]` for date ranges). On the backend, declare each id with `DatatableApi::withFilters()`:

```php
->withFilters([
    'status' => fn ($q, string $value) => $q->where('status', $value),
])
```

Undeclared ids are ignored server-side with a log warning.

### Sorting is whitelisted server-side

The backend enforces a sort whitelist via `DatatableApi::withSortableColumns(...)`. Only
expose sortable columns (`enableSorting: true`) that the backend actually allows, otherwise
the sort is dropped server-side with a warning. Without `withSortableColumns()` no column is sortable (custom sorts aside).
