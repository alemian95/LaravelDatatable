# React frontend package — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `@alemian95/laraveldatatable-react`, a self-contained React datatable that drives the backend's HTTP contract with search, sort, pagination, column visibility, a filters sheet, and bulk actions.

**Architecture:** A `react/` npm workspace in this repo. A pure `buildParams` function maps normalized query state to the backend query string (the testable core). `useDatatable` wraps TanStack Query around it with configurable auth headers from context. `DataTable` runs TanStack Table in manual (server-side) mode and translates its state into the normalized query. UI is bundled shadcn-look primitives (Radix + Tailwind), so no dependency on the consumer's `ui/`.

**Tech Stack:** TypeScript, React 18+, TanStack Query v5, TanStack Table v8, Radix UI, Tailwind, tsup (build), Vitest + React Testing Library (test).

## Global Constraints

- Package name: `@alemian95/laraveldatatable-react`.
- Peer deps (NOT bundled): `react`, `react-dom`, `@tanstack/react-query`, `tailwindcss`.
- Bundled deps: `@tanstack/react-table`, `@radix-ui/react-dialog`, `@radix-ui/react-dropdown-menu`, `@radix-ui/react-checkbox`, `@radix-ui/react-select`, `clsx`.
- All work lives under `react/`; do not touch the PHP `src/`.
- Server-side only: TanStack Table runs with `manualPagination`, `manualSorting`, `manualFiltering` = true.
- Query param contract (maps 1:1 onto backend `DatatableRequest`): `page` (1-based), `per_page`, `search`, `search_columns` (csv), `sort_by`, `sort_order` (`asc`|`desc`), `filter[<id>]` / `filter[<id>][from]` / `filter[<id>][to]`.
- Response shape: Laravel length-aware paginator (`data`, `current_page`, `last_page`, `per_page`, `total`).
- Sentence case in all UI copy; no emoji.
- Node 20 for CI/build.

---

### Task 1: Package scaffold

**Files:**
- Create: `react/package.json`
- Create: `react/tsconfig.json`
- Create: `react/tsup.config.ts`
- Create: `react/vitest.config.ts`
- Create: `react/src/index.ts` (temporary empty export)
- Create: `react/.gitignore`

**Interfaces:**
- Consumes: nothing.
- Produces: a buildable/testable workspace. Build entry `react/src/index.ts`.

- [ ] **Step 1: Create `react/package.json`**

```json
{
  "name": "@alemian95/laraveldatatable-react",
  "version": "0.1.0",
  "description": "React datatable for the alemian95/laraveldatatable Laravel package.",
  "license": "MIT",
  "type": "module",
  "main": "./dist/index.js",
  "module": "./dist/index.js",
  "types": "./dist/index.d.ts",
  "exports": { ".": { "types": "./dist/index.d.ts", "import": "./dist/index.js" } },
  "files": ["dist"],
  "sideEffects": false,
  "scripts": {
    "build": "tsup",
    "test": "vitest run",
    "test:watch": "vitest",
    "typecheck": "tsc --noEmit"
  },
  "peerDependencies": {
    "react": "^18 || ^19",
    "react-dom": "^18 || ^19",
    "@tanstack/react-query": "^5",
    "tailwindcss": "^3 || ^4"
  },
  "dependencies": {
    "@tanstack/react-table": "^8",
    "@radix-ui/react-dialog": "^1",
    "@radix-ui/react-dropdown-menu": "^2",
    "@radix-ui/react-checkbox": "^1",
    "@radix-ui/react-select": "^2",
    "clsx": "^2"
  },
  "devDependencies": {
    "@tanstack/react-query": "^5",
    "@testing-library/react": "^16",
    "@testing-library/user-event": "^14",
    "@types/react": "^18",
    "@types/react-dom": "^18",
    "jsdom": "^25",
    "react": "^18",
    "react-dom": "^18",
    "tsup": "^8",
    "typescript": "^5",
    "vitest": "^2"
  }
}
```

- [ ] **Step 2: Create `react/tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "jsx": "react-jsx",
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "declaration": true,
    "outDir": "dist"
  },
  "include": ["src"]
}
```

- [ ] **Step 3: Create `react/tsup.config.ts`**

```ts
import { defineConfig } from 'tsup'

export default defineConfig({
  entry: ['src/index.ts'],
  format: ['esm'],
  dts: true,
  clean: true,
  sourcemap: true,
  external: ['react', 'react-dom', '@tanstack/react-query'],
})
```

- [ ] **Step 4: Create `react/vitest.config.ts`**

```ts
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: { environment: 'jsdom', globals: true },
})
```

- [ ] **Step 5: Create `react/.gitignore` and temporary `react/src/index.ts`**

`react/.gitignore`:
```
node_modules
dist
```

`react/src/index.ts`:
```ts
export {}
```

- [ ] **Step 6: Install and verify build**

Run: `cd react && npm install && npm run build`
Expected: `dist/index.js` and `dist/index.d.ts` produced, no errors.

- [ ] **Step 7: Commit**

```bash
git add react/package.json react/tsconfig.json react/tsup.config.ts react/vitest.config.ts react/.gitignore react/src/index.ts
git commit -m "chore(react): scaffold @alemian95/laraveldatatable-react workspace"
```

---

### Task 2: Types + `buildParams` (the testable core)

**Files:**
- Create: `react/src/types.ts`
- Create: `react/src/build-params.ts`
- Test: `react/src/build-params.test.ts`

**Interfaces:**
- Produces:
  - `types.ts`: `HeadersResolver`, `DatatableConfig`, `ColumnMeta`, `FilterDef`, `FilterValue`, `BulkAction<T>`, `PaginatorResponse<T>`, `DatatableQuery`.
  - `build-params.ts`: `buildParams(query: DatatableQuery): URLSearchParams`.

- [ ] **Step 1: Create `react/src/types.ts`**

```ts
export type HeadersResolver =
  | HeadersInit
  | (() => HeadersInit | Promise<HeadersInit>)

export interface DatatableConfig {
  baseUrl: string
  headers?: HeadersResolver
}

export interface ColumnMeta {
  searchable?: boolean
  sortKey?: string
}

export type FilterValue = string | { from?: string; to?: string }

export type FilterDef =
  | { id: string; label: string; type: 'select'; options: { value: string; label: string }[] }
  | { id: string; label: string; type: 'date-range' }
  | { id: string; label: string; type: 'text' }

export interface BulkAction<T> {
  value: string
  label: string
  handler: (selectedRows: T[]) => void | Promise<void>
}

export interface PaginatorResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface DatatableQuery {
  page: number
  perPage: number
  search?: string
  searchColumns?: string[]
  sortBy?: string
  sortOrder?: 'asc' | 'desc'
  filters?: Record<string, FilterValue>
}
```

- [ ] **Step 2: Write the failing test `react/src/build-params.test.ts`**

```ts
import { describe, it, expect } from 'vitest'
import { buildParams } from './build-params'

const s = (q: Parameters<typeof buildParams>[0]) => buildParams(q).toString()

describe('buildParams', () => {
  it('sets page and per_page always', () => {
    expect(s({ page: 2, perPage: 25 })).toBe('page=2&per_page=25')
  })

  it('adds search and search_columns when present', () => {
    const out = buildParams({ page: 1, perPage: 15, search: 'jane', searchColumns: ['name', 'email'] })
    expect(out.get('search')).toBe('jane')
    expect(out.get('search_columns')).toBe('name,email')
  })

  it('omits search_columns when search is empty', () => {
    const out = buildParams({ page: 1, perPage: 15, search: '', searchColumns: ['name'] })
    expect(out.has('search')).toBe(false)
    expect(out.has('search_columns')).toBe(false)
  })

  it('maps sort_by and sort_order', () => {
    const out = buildParams({ page: 1, perPage: 15, sortBy: 'created_at', sortOrder: 'desc' })
    expect(out.get('sort_by')).toBe('created_at')
    expect(out.get('sort_order')).toBe('desc')
  })

  it('emits scalar and date-range filters, skipping empty values', () => {
    const out = buildParams({
      page: 1, perPage: 15,
      filters: { status: 'active', role: '', created_at: { from: '2026-06-01', to: '' } },
    })
    expect(out.get('filter[status]')).toBe('active')
    expect(out.has('filter[role]')).toBe(false)
    expect(out.get('filter[created_at][from]')).toBe('2026-06-01')
    expect(out.has('filter[created_at][to]')).toBe(false)
  })
})
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd react && npx vitest run src/build-params.test.ts`
Expected: FAIL — cannot resolve `./build-params`.

- [ ] **Step 4: Implement `react/src/build-params.ts`**

```ts
import type { DatatableQuery } from './types'

export function buildParams(query: DatatableQuery): URLSearchParams {
  const p = new URLSearchParams()
  p.set('page', String(query.page))
  p.set('per_page', String(query.perPage))

  if (query.search) {
    p.set('search', query.search)
    if (query.searchColumns && query.searchColumns.length > 0) {
      p.set('search_columns', query.searchColumns.join(','))
    }
  }

  if (query.sortBy) {
    p.set('sort_by', query.sortBy)
    p.set('sort_order', query.sortOrder ?? 'asc')
  }

  for (const [id, value] of Object.entries(query.filters ?? {})) {
    if (value == null) continue
    if (typeof value === 'object') {
      if (value.from) p.set(`filter[${id}][from]`, value.from)
      if (value.to) p.set(`filter[${id}][to]`, value.to)
    } else if (value !== '') {
      p.set(`filter[${id}]`, value)
    }
  }

  return p
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd react && npx vitest run src/build-params.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add react/src/types.ts react/src/build-params.ts react/src/build-params.test.ts
git commit -m "feat(react): types and buildParams query-string mapping"
```

---

### Task 3: DatatableProvider + context + header resolution

**Files:**
- Create: `react/src/provider.tsx`
- Create: `react/src/resolve-headers.ts`
- Test: `react/src/resolve-headers.test.ts`

**Interfaces:**
- Consumes: `DatatableConfig`, `HeadersResolver` from `types.ts`.
- Produces:
  - `DatatableProvider: React.FC<{ config: DatatableConfig; children: React.ReactNode }>`
  - `useDatatableConfig(): DatatableConfig` (throws if no provider)
  - `resolveHeaders(h?: HeadersResolver): Promise<HeadersInit>`

- [ ] **Step 1: Write the failing test `react/src/resolve-headers.test.ts`**

```ts
import { describe, it, expect } from 'vitest'
import { resolveHeaders } from './resolve-headers'

describe('resolveHeaders', () => {
  it('returns {} when undefined', async () => {
    expect(await resolveHeaders(undefined)).toEqual({})
  })

  it('returns a static object as-is', async () => {
    expect(await resolveHeaders({ Authorization: 'Bearer x' })).toEqual({ Authorization: 'Bearer x' })
  })

  it('calls a sync function', async () => {
    expect(await resolveHeaders(() => ({ Authorization: 'Bearer y' }))).toEqual({ Authorization: 'Bearer y' })
  })

  it('awaits an async function', async () => {
    expect(await resolveHeaders(async () => ({ Authorization: 'Bearer z' }))).toEqual({ Authorization: 'Bearer z' })
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd react && npx vitest run src/resolve-headers.test.ts`
Expected: FAIL — cannot resolve `./resolve-headers`.

- [ ] **Step 3: Implement `react/src/resolve-headers.ts`**

```ts
import type { HeadersResolver } from './types'

export async function resolveHeaders(h?: HeadersResolver): Promise<HeadersInit> {
  if (!h) return {}
  if (typeof h === 'function') return await h()
  return h
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd react && npx vitest run src/resolve-headers.test.ts`
Expected: PASS (4 tests).

- [ ] **Step 5: Implement `react/src/provider.tsx`**

```tsx
import { createContext, useContext } from 'react'
import type { DatatableConfig } from './types'

const DatatableContext = createContext<DatatableConfig | null>(null)

export function DatatableProvider({
  config,
  children,
}: {
  config: DatatableConfig
  children: React.ReactNode
}) {
  return <DatatableContext.Provider value={config}>{children}</DatatableContext.Provider>
}

export function useDatatableConfig(): DatatableConfig {
  const ctx = useContext(DatatableContext)
  if (!ctx) {
    throw new Error('useDatatableConfig must be used within a <DatatableProvider>. See the package README.')
  }
  return ctx
}
```

- [ ] **Step 6: Commit**

```bash
git add react/src/provider.tsx react/src/resolve-headers.ts react/src/resolve-headers.test.ts
git commit -m "feat(react): DatatableProvider context and header resolution"
```

---

### Task 4: useDatatable hook (TanStack Query)

**Files:**
- Create: `react/src/use-datatable.ts`
- Test: `react/src/use-datatable.test.tsx`

**Interfaces:**
- Consumes: `useDatatableConfig`, `resolveHeaders`, `buildParams`, `DatatableQuery`, `PaginatorResponse`.
- Produces:
  ```ts
  function useDatatable<T>(endpoint: string, query: DatatableQuery): {
    rows: T[]; pageCount: number; total: number;
    isLoading: boolean; isFetching: boolean; error: Error | null; refetch: () => void;
  }
  ```

- [ ] **Step 1: Write the failing test `react/src/use-datatable.test.tsx`**

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { DatatableProvider } from './provider'
import { useDatatable } from './use-datatable'

function wrapper(headers?: any) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={client}>
      <DatatableProvider config={{ baseUrl: 'https://api.test', headers }}>{children}</DatatableProvider>
    </QueryClientProvider>
  )
}

const paginator = {
  data: [{ id: 1, name: 'Jane' }],
  current_page: 1, last_page: 5, per_page: 15, total: 75,
}

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => paginator })))
})

describe('useDatatable', () => {
  it('fetches the endpoint with built params and returns normalized data', async () => {
    const { result } = renderHook(
      () => useDatatable('/users', { page: 2, perPage: 15, search: 'jane', searchColumns: ['name'] }),
      { wrapper: wrapper() },
    )
    await waitFor(() => expect(result.current.isLoading).toBe(false))

    const url = (fetch as any).mock.calls[0][0] as string
    expect(url).toContain('https://api.test/users?')
    expect(url).toContain('page=2')
    expect(url).toContain('search=jane')
    expect(url).toContain('search_columns=name')
    expect(result.current.rows).toEqual(paginator.data)
    expect(result.current.pageCount).toBe(5)
    expect(result.current.total).toBe(75)
  })

  it('sends resolved async auth headers', async () => {
    const { result } = renderHook(
      () => useDatatable('/users', { page: 1, perPage: 15 }),
      { wrapper: wrapper(async () => ({ Authorization: 'Bearer tok' })) },
    )
    await waitFor(() => expect(result.current.isLoading).toBe(false))
    const init = (fetch as any).mock.calls[0][1]
    expect(init.headers).toEqual({ Authorization: 'Bearer tok' })
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd react && npx vitest run src/use-datatable.test.tsx`
Expected: FAIL — cannot resolve `./use-datatable`.

- [ ] **Step 3: Implement `react/src/use-datatable.ts`**

```ts
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useDatatableConfig } from './provider'
import { resolveHeaders } from './resolve-headers'
import { buildParams } from './build-params'
import type { DatatableQuery, PaginatorResponse } from './types'

export function useDatatable<T>(endpoint: string, query: DatatableQuery) {
  const config = useDatatableConfig()

  const q = useQuery({
    queryKey: [endpoint, query],
    placeholderData: keepPreviousData,
    queryFn: async (): Promise<PaginatorResponse<T>> => {
      const headers = await resolveHeaders(config.headers)
      const url = `${config.baseUrl}${endpoint}?${buildParams(query).toString()}`
      const res = await fetch(url, { headers })
      if (!res.ok) throw new Error(`Request failed with status ${res.status}`)
      return res.json()
    },
  })

  return {
    rows: q.data?.data ?? [],
    pageCount: q.data?.last_page ?? 0,
    total: q.data?.total ?? 0,
    isLoading: q.isLoading,
    isFetching: q.isFetching,
    error: q.error as Error | null,
    refetch: q.refetch,
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd react && npx vitest run src/use-datatable.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add react/src/use-datatable.ts react/src/use-datatable.test.tsx
git commit -m "feat(react): useDatatable TanStack Query hook"
```

---

### Task 5: Bundled shadcn-look UI primitives

**Files:**
- Create: `react/src/lib/cn.ts`
- Create: `react/src/ui/table.tsx`
- Create: `react/src/ui/button.tsx`
- Create: `react/src/ui/input.tsx`
- Create: `react/src/ui/checkbox.tsx`
- Create: `react/src/ui/select.tsx`
- Create: `react/src/ui/dropdown-menu.tsx`
- Create: `react/src/ui/sheet.tsx`
- Create: `react/src/ui/badge.tsx`

**Interfaces:**
- Consumes: nothing package-internal (Radix + React).
- Produces: standard shadcn primitive components exported from each file (`Table`, `TableHeader`, `TableBody`, `TableRow`, `TableHead`, `TableCell`; `Button`; `Input`; `Checkbox`; `Select`, `SelectTrigger`, `SelectContent`, `SelectItem`, `SelectValue`; `DropdownMenu*`; `Sheet`, `SheetTrigger`, `SheetContent`, `SheetHeader`, `SheetTitle`; `Badge`).

**Note:** These are the canonical shadcn/ui component sources, vendored verbatim except that each Radix import stays as its npm package (already a dependency) and the shared class helper is `./lib/cn` below. Do NOT hand-rewrite them — copy the current shadcn/ui source for each primitive. `cn` is the standard helper.

- [ ] **Step 1: Create `react/src/lib/cn.ts`**

```ts
import clsx, { type ClassValue } from 'clsx'
export function cn(...inputs: ClassValue[]): string {
  return clsx(inputs)
}
```

- [ ] **Step 2: Vendor each primitive**

For each file in **Files** above, copy the corresponding canonical shadcn/ui primitive source (New York style), replacing its `@/lib/utils` import with `../lib/cn` and keeping the `@radix-ui/*` imports as-is. Tailwind utility classes remain inline strings. Keep every component's public export names exactly as shadcn ships them (listed under **Produces**).

- [ ] **Step 3: Typecheck**

Run: `cd react && npm run typecheck`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add react/src/lib/cn.ts react/src/ui
git commit -m "feat(react): vendor shadcn-look UI primitives (Radix + Tailwind)"
```

---

### Task 6: DataTable core (table + pagination, server-side)

**Files:**
- Create: `react/src/data-table.tsx`
- Test: `react/src/data-table.test.tsx`

**Interfaces:**
- Consumes: `useDatatable`, all `ui/*`, `ColumnMeta`, `FilterDef`, `BulkAction`, `FilterValue`, TanStack Table.
- Produces:
  ```ts
  interface DataTableProps<T> {
    endpoint: string
    columns: ColumnDef<T, unknown>[]
    defaultPerPage?: number
    filters?: FilterDef[]
    bulkActions?: BulkAction<T>[]
  }
  function DataTable<T>(props: DataTableProps<T>): JSX.Element
  ```
  Uses `@tanstack/react-table` `useReactTable` with `manualPagination/Sorting/Filtering`, `pageCount` from the hook. Derives `DatatableQuery` from state: `page = pagination.pageIndex + 1`; `searchColumns` = ids of visible columns whose `meta.searchable` is true; `sortBy` = `sorting[0]` resolved via `meta.sortKey ?? column.id`; `sortOrder` = `sorting[0].desc ? 'desc' : 'asc'`.

- [ ] **Step 1: Write the failing test `react/src/data-table.test.tsx`**

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { DatatableProvider } from './provider'
import { DataTable } from './data-table'

type User = { id: number; name: string; email: string }
const columns: ColumnDef<User, unknown>[] = [
  { accessorKey: 'name', header: 'Name', enableSorting: true, meta: { searchable: true } },
  { accessorKey: 'email', header: 'Email', meta: { searchable: true } },
]

function renderTable(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <DatatableProvider config={{ baseUrl: 'https://api.test' }}>{ui}</DatatableProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async () => ({
    ok: true,
    json: async () => ({ data: [{ id: 1, name: 'Jane', email: 'jane@test' }], current_page: 1, last_page: 1, per_page: 15, total: 1 }),
  })))
})

describe('DataTable', () => {
  it('renders rows from the paginator response', async () => {
    renderTable(<DataTable<User> endpoint="/users" columns={columns} />)
    await waitFor(() => expect(screen.getByText('Jane')).toBeTruthy())
    expect(screen.getByText('jane@test')).toBeTruthy()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd react && npx vitest run src/data-table.test.tsx`
Expected: FAIL — cannot resolve `./data-table`.

- [ ] **Step 3: Implement `react/src/data-table.tsx`**

Implement `DataTable` with `useReactTable` in manual mode. State hooks: `pagination` (`{ pageIndex: 0, pageSize: defaultPerPage ?? 15 }`), `sorting` (`SortingState`), `search` (raw) + `debouncedSearch` (300ms via `useEffect` + `setTimeout`), `columnVisibility`, `filters` (`Record<string, FilterValue>`), `rowSelection`. Build the `DatatableQuery` (memoized) and call `useDatatable`. Render: `<Toolbar>` (Task 7), `<Table>` with `flexRender` headers (click toggles sorting when `column.getCanSort()`), body rows (skeleton while `isLoading`, error row with Retry on `error`, empty row when no rows), and `<Pagination>` footer (Previous/Next from `table.previousPage()/nextPage()`, `Rows per page` select bound to `pagination.pageSize`, "Page X of pageCount"). Enable the selection column only when `bulkActions?.length`. Pass `filters`, `bulkActions`, selection, and search state down to `<Toolbar>`.

```tsx
import { useEffect, useMemo, useState } from 'react'
import {
  flexRender, getCoreRowModel, useReactTable,
  type ColumnDef, type SortingState, type VisibilityState, type RowSelectionState,
} from '@tanstack/react-table'
import { useDatatable } from './use-datatable'
import { Toolbar } from './toolbar'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from './ui/table'
import { Button } from './ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'
import type { BulkAction, ColumnMeta, DatatableQuery, FilterDef, FilterValue } from './types'

export interface DataTableProps<T> {
  endpoint: string
  columns: ColumnDef<T, unknown>[]
  defaultPerPage?: number
  filters?: FilterDef[]
  bulkActions?: BulkAction<T>[]
}

export function DataTable<T>({ endpoint, columns, defaultPerPage = 15, filters, bulkActions }: DataTableProps<T>) {
  const [pagination, setPagination] = useState({ pageIndex: 0, pageSize: defaultPerPage })
  const [sorting, setSorting] = useState<SortingState>([])
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>({})
  const [filterValues, setFilterValues] = useState<Record<string, FilterValue>>({})
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({})

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(t)
  }, [search])

  const searchColumns = useMemo(
    () => columns.filter((c) => (c.meta as ColumnMeta | undefined)?.searchable)
      .map((c) => ('accessorKey' in c ? String(c.accessorKey) : c.id!))
      .filter((id) => columnVisibility[id] !== false),
    [columns, columnVisibility],
  )

  const query: DatatableQuery = useMemo(() => {
    const sort = sorting[0]
    const sortMeta = sort && (columns.find((c) => ('accessorKey' in c ? String(c.accessorKey) : c.id) === sort.id)?.meta as ColumnMeta | undefined)
    return {
      page: pagination.pageIndex + 1,
      perPage: pagination.pageSize,
      search: debouncedSearch || undefined,
      searchColumns,
      sortBy: sort ? (sortMeta?.sortKey ?? sort.id) : undefined,
      sortOrder: sort ? (sort.desc ? 'desc' : 'asc') : undefined,
      filters: filterValues,
    }
  }, [pagination, sorting, debouncedSearch, searchColumns, filterValues, columns])

  const { rows, pageCount, total, isLoading, error, refetch } = useDatatable<T>(endpoint, query)

  const table = useReactTable({
    data: rows,
    columns,
    pageCount,
    state: { pagination, sorting, columnVisibility, rowSelection },
    manualPagination: true,
    manualSorting: true,
    manualFiltering: true,
    enableRowSelection: !!bulkActions?.length,
    onPaginationChange: setPagination,
    onSortingChange: setSorting,
    onColumnVisibilityChange: setColumnVisibility,
    onRowSelectionChange: setRowSelection,
    getCoreRowModel: getCoreRowModel(),
  })

  const selectedRows = table.getSelectedRowModel().rows.map((r) => r.original)

  return (
    <div className="space-y-3">
      <Toolbar
        table={table}
        search={search}
        onSearch={setSearch}
        filters={filters}
        filterValues={filterValues}
        onFilters={setFilterValues}
        bulkActions={bulkActions}
        selectedRows={selectedRows}
        onActionDone={() => { setRowSelection({}); refetch() }}
      />

      <div className="rounded-xl border">
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((hg) => (
              <TableRow key={hg.id}>
                {hg.headers.map((h) => (
                  <TableHead
                    key={h.id}
                    onClick={h.column.getCanSort() ? h.column.getToggleSortingHandler() : undefined}
                    className={h.column.getCanSort() ? 'cursor-pointer select-none' : undefined}
                  >
                    {h.isPlaceholder ? null : flexRender(h.column.columnDef.header, h.getContext())}
                    {{ asc: ' ↑', desc: ' ↓' }[h.column.getIsSorted() as string] ?? ''}
                  </TableHead>
                ))}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <TableRow><TableCell colSpan={columns.length}>Loading…</TableCell></TableRow>
            ) : error ? (
              <TableRow><TableCell colSpan={columns.length}>
                Couldn't load the data. <Button onClick={() => refetch()}>Retry</Button>
              </TableCell></TableRow>
            ) : rows.length === 0 ? (
              <TableRow><TableCell colSpan={columns.length}>No results.</TableCell></TableRow>
            ) : (
              table.getRowModel().rows.map((row) => (
                <TableRow key={row.id} data-state={row.getIsSelected() ? 'selected' : undefined}>
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</TableCell>
                  ))}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      <div className="flex items-center justify-between text-sm text-muted-foreground">
        <span>{total} total</span>
        <div className="flex items-center gap-4">
          <Select value={String(pagination.pageSize)} onValueChange={(v) => setPagination((p) => ({ ...p, pageIndex: 0, pageSize: Number(v) }))}>
            <SelectTrigger className="w-20"><SelectValue /></SelectTrigger>
            <SelectContent>{[15, 25, 50].map((n) => <SelectItem key={n} value={String(n)}>{n}</SelectItem>)}</SelectContent>
          </Select>
          <span>Page {pagination.pageIndex + 1} of {Math.max(pageCount, 1)}</span>
          <div className="flex gap-2">
            <Button onClick={() => table.previousPage()} disabled={!table.getCanPreviousPage()}>Previous</Button>
            <Button onClick={() => table.nextPage()} disabled={!table.getCanNextPage()}>Next</Button>
          </div>
        </div>
      </div>
    </div>
  )
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd react && npx vitest run src/data-table.test.tsx`
Expected: PASS (1 test). (Depends on Task 7 `Toolbar` existing; if running strictly in order, stub `Toolbar` to `return null` first, then complete Task 7. Prefer implementing Task 7 before running this step.)

- [ ] **Step 5: Commit**

```bash
git add react/src/data-table.tsx react/src/data-table.test.tsx
git commit -m "feat(react): DataTable core with server-side pagination and sorting"
```

---

### Task 7: Toolbar (search, column visibility, bulk actions)

**Files:**
- Create: `react/src/toolbar.tsx`
- Test: `react/src/toolbar.test.tsx`

**Interfaces:**
- Consumes: `ui/*`, `FilterDef`, `FilterValue`, `BulkAction`, TanStack `Table<T>`, and `FiltersSheet` (Task 8).
- Produces:
  ```ts
  interface ToolbarProps<T> {
    table: Table<T>
    search: string
    onSearch: (v: string) => void
    filters?: FilterDef[]
    filterValues: Record<string, FilterValue>
    onFilters: (v: Record<string, FilterValue>) => void
    bulkActions?: BulkAction<T>[]
    selectedRows: T[]
    onActionDone: () => void
  }
  function Toolbar<T>(props: ToolbarProps<T>): JSX.Element
  ```

- [ ] **Step 1: Write the failing test `react/src/toolbar.test.tsx`**

```tsx
import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Toolbar } from './toolbar'

const fakeTable = { getAllColumns: () => [] } as any

describe('Toolbar', () => {
  it('shows the selected count and runs the chosen bulk action', async () => {
    const handler = vi.fn()
    const onActionDone = vi.fn()
    render(
      <Toolbar
        table={fakeTable}
        search=""
        onSearch={() => {}}
        filterValues={{}}
        onFilters={() => {}}
        bulkActions={[{ value: 'del', label: 'Delete', handler }]}
        selectedRows={[{ id: 1 }, { id: 2 }]}
        onActionDone={onActionDone}
      />,
    )
    expect(screen.getByText('2 selected')).toBeTruthy()

    await userEvent.selectOptions(screen.getByLabelText('Bulk action'), 'del')
    await userEvent.click(screen.getByText('Apply'))
    expect(handler).toHaveBeenCalledWith([{ id: 1 }, { id: 2 }])
    expect(onActionDone).toHaveBeenCalled()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd react && npx vitest run src/toolbar.test.tsx`
Expected: FAIL — cannot resolve `./toolbar`.

- [ ] **Step 3: Implement `react/src/toolbar.tsx`**

Left side: bulk-actions bar when `selectedRows.length > 0` — a `"{n} selected"` label, a native `<select aria-label="Bulk action">` listing `bulkActions`, and an Apply `<Button>` that looks up the selected action by `value`, awaits `handler(selectedRows)`, then calls `onActionDone()`. When no selection, render the search `Input` (value `search`, `onChange` → `onSearch`) with a leading search icon. Right side: a Filters `Button` that opens `FiltersSheet` (only if `filters?.length`) with an active-count badge (count of non-empty `filterValues`), and a Columns `DropdownMenu` listing `table.getAllColumns().filter(c => c.getCanHide())` each as a checkbox item bound to `column.getIsVisible()/toggleVisibility()`. Use the native `<select>` for the bulk action to keep the test DOM-simple.

```tsx
import { useState } from 'react'
import type { Table } from '@tanstack/react-table'
import { Button } from './ui/button'
import { Input } from './ui/input'
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuCheckboxItem } from './ui/dropdown-menu'
import { FiltersSheet } from './filters-sheet'
import type { BulkAction, FilterDef, FilterValue } from './types'

export interface ToolbarProps<T> {
  table: Table<T>
  search: string
  onSearch: (v: string) => void
  filters?: FilterDef[]
  filterValues: Record<string, FilterValue>
  onFilters: (v: Record<string, FilterValue>) => void
  bulkActions?: BulkAction<T>[]
  selectedRows: T[]
  onActionDone: () => void
}

export function Toolbar<T>(props: ToolbarProps<T>) {
  const { table, search, onSearch, filters, filterValues, onFilters, bulkActions, selectedRows, onActionDone } = props
  const [action, setAction] = useState('')
  const activeFilters = Object.values(filterValues).filter((v) =>
    typeof v === 'object' ? v.from || v.to : v !== '' && v != null).length

  async function apply() {
    const chosen = bulkActions?.find((a) => a.value === action)
    if (!chosen) return
    await chosen.handler(selectedRows)
    onActionDone()
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex items-center gap-2">
        {selectedRows.length > 0 && bulkActions?.length ? (
          <>
            <span className="rounded-md bg-accent px-3 py-1 text-sm">{selectedRows.length} selected</span>
            <select aria-label="Bulk action" value={action} onChange={(e) => setAction(e.target.value)}>
              <option value="">Actions…</option>
              {bulkActions.map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}
            </select>
            <Button onClick={apply}>Apply</Button>
          </>
        ) : (
          <Input placeholder="Search…" value={search} onChange={(e) => onSearch(e.target.value)} className="max-w-xs" />
        )}
      </div>

      <div className="flex items-center gap-2">
        {filters?.length ? (
          <FiltersSheet
            filters={filters}
            values={filterValues}
            onApply={onFilters}
            activeCount={activeFilters}
          />
        ) : null}
        <DropdownMenu>
          <DropdownMenuTrigger asChild><Button>Columns</Button></DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {table.getAllColumns().filter((c) => c.getCanHide()).map((c) => (
              <DropdownMenuCheckboxItem
                key={c.id}
                checked={c.getIsVisible()}
                onCheckedChange={(v) => c.toggleVisibility(!!v)}
              >
                {c.id}
              </DropdownMenuCheckboxItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  )
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd react && npx vitest run src/toolbar.test.tsx`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add react/src/toolbar.tsx react/src/toolbar.test.tsx
git commit -m "feat(react): toolbar with search, column visibility, bulk actions"
```

---

### Task 8: FiltersSheet (slide-over)

**Files:**
- Create: `react/src/filters-sheet.tsx`
- Test: `react/src/filters-sheet.test.tsx`

**Interfaces:**
- Consumes: `ui/sheet`, `ui/button`, `ui/input`, `ui/select`, `FilterDef`, `FilterValue`.
- Produces:
  ```ts
  interface FiltersSheetProps {
    filters: FilterDef[]
    values: Record<string, FilterValue>
    onApply: (v: Record<string, FilterValue>) => void
    activeCount: number
  }
  function FiltersSheet(props: FiltersSheetProps): JSX.Element
  ```
  Holds a local draft copy of `values`; Apply calls `onApply(draft)` and closes; Reset sets the draft to `{}`. Renders each `FilterDef` by `type`: `select` → `Select` of `options`; `date-range` → two `Input type="date"` bound to `.from`/`.to`; `text` → `Input`. The trigger is a Filters `Button` showing `activeCount` as a badge when > 0.

- [ ] **Step 1: Write the failing test `react/src/filters-sheet.test.tsx`**

```tsx
import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { FiltersSheet } from './filters-sheet'

describe('FiltersSheet', () => {
  it('applies a text filter draft', async () => {
    const onApply = vi.fn()
    render(
      <FiltersSheet
        filters={[{ id: 'city', label: 'City', type: 'text' }]}
        values={{}}
        onApply={onApply}
        activeCount={0}
      />,
    )
    await userEvent.click(screen.getByText('Filters'))
    await userEvent.type(screen.getByLabelText('City'), 'Rome')
    await userEvent.click(screen.getByText('Apply filters'))
    expect(onApply).toHaveBeenCalledWith({ city: 'Rome' })
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd react && npx vitest run src/filters-sheet.test.tsx`
Expected: FAIL — cannot resolve `./filters-sheet`.

- [ ] **Step 3: Implement `react/src/filters-sheet.tsx`**

Implement per the interface above using the vendored `Sheet` primitives. Manage `open` and a `draft` state seeded from `values` when opened. Give each control an accessible label equal to the filter's `label` (`<label>` + control, or `aria-label`) so the test's `getByLabelText` resolves. Apply: `onApply(draft); setOpen(false)`. Reset: `setDraft({})`.

```tsx
import { useState } from 'react'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from './ui/sheet'
import { Button } from './ui/button'
import { Input } from './ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'
import type { FilterDef, FilterValue } from './types'

export interface FiltersSheetProps {
  filters: FilterDef[]
  values: Record<string, FilterValue>
  onApply: (v: Record<string, FilterValue>) => void
  activeCount: number
}

export function FiltersSheet({ filters, values, onApply, activeCount }: FiltersSheetProps) {
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState<Record<string, FilterValue>>(values)

  function set(id: string, v: FilterValue) { setDraft((d) => ({ ...d, [id]: v })) }

  return (
    <Sheet open={open} onOpenChange={(o) => { setOpen(o); if (o) setDraft(values) }}>
      <SheetTrigger asChild>
        <Button>Filters{activeCount > 0 ? ` (${activeCount})` : ''}</Button>
      </SheetTrigger>
      <SheetContent side="right">
        <SheetHeader><SheetTitle>Filters</SheetTitle></SheetHeader>
        <div className="mt-4 space-y-4">
          {filters.map((f) => (
            <div key={f.id} className="space-y-1">
              <label htmlFor={`f-${f.id}`} className="text-sm text-muted-foreground">{f.label}</label>
              {f.type === 'text' && (
                <Input id={`f-${f.id}`} aria-label={f.label}
                  value={(draft[f.id] as string) ?? ''} onChange={(e) => set(f.id, e.target.value)} />
              )}
              {f.type === 'select' && (
                <Select value={(draft[f.id] as string) ?? ''} onValueChange={(v) => set(f.id, v)}>
                  <SelectTrigger id={`f-${f.id}`} aria-label={f.label}><SelectValue placeholder="Any" /></SelectTrigger>
                  <SelectContent>{f.options.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
                </Select>
              )}
              {f.type === 'date-range' && (
                <div className="flex gap-2">
                  <Input aria-label={`${f.label} from`} type="date"
                    value={(draft[f.id] as { from?: string })?.from ?? ''}
                    onChange={(e) => set(f.id, { ...(draft[f.id] as object), from: e.target.value })} />
                  <Input aria-label={`${f.label} to`} type="date"
                    value={(draft[f.id] as { to?: string })?.to ?? ''}
                    onChange={(e) => set(f.id, { ...(draft[f.id] as object), to: e.target.value })} />
                </div>
              )}
            </div>
          ))}
          <div className="flex gap-2 pt-2">
            <Button className="flex-1" onClick={() => setDraft({})}>Reset</Button>
            <Button className="flex-1" onClick={() => { onApply(draft); setOpen(false) }}>Apply filters</Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  )
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd react && npx vitest run src/filters-sheet.test.tsx`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add react/src/filters-sheet.tsx react/src/filters-sheet.test.tsx
git commit -m "feat(react): filters slide-over sheet"
```

---

### Task 9: Public exports, README, full build/test

**Files:**
- Modify: `react/src/index.ts`
- Create: `react/README.md`

**Interfaces:**
- Produces: the package's public surface — `DatatableProvider`, `DataTable`, `useDatatable`, and all public types.

- [ ] **Step 1: Replace `react/src/index.ts`**

```ts
export { DatatableProvider, useDatatableConfig } from './provider'
export { DataTable, type DataTableProps } from './data-table'
export { useDatatable } from './use-datatable'
export type {
  DatatableConfig, HeadersResolver, ColumnMeta, FilterDef, FilterValue,
  BulkAction, PaginatorResponse, DatatableQuery,
} from './types'
```

- [ ] **Step 2: Write `react/README.md`**

Cover: install (`npm install @alemian95/laraveldatatable-react`), peer deps, the Tailwind `content` glob to add (`./node_modules/@alemian95/laraveldatatable-react/dist/**/*.js`), a full usage example (provider + columns with `meta`, `filters`, `bulkActions`), the query-param contract table, and the "filters are emitted as `filter[...]`; wire them server-side via `withCustomFilters`" note. Include the sort-whitelist caveat: only expose sortable columns the backend's `withSortableColumns` allows.

- [ ] **Step 3: Full typecheck, test, and build**

Run: `cd react && npm run typecheck && npm test && npm run build`
Expected: typecheck clean; all tests pass (build-params 5, resolve-headers 4, use-datatable 2, data-table 1, toolbar 1, filters-sheet 1); `dist/` produced with `index.d.ts`.

- [ ] **Step 4: Commit**

```bash
git add react/src/index.ts react/README.md
git commit -m "feat(react): public exports and README"
```

---

### Task 10: CI job

**Files:**
- Create: `.github/workflows/react.yml`
- Modify: `.github/workflows/run-tests.yml` (add a `paths-ignore`/`paths` guard so PHP tests skip `react/**`-only changes — only if the workflow does not already scope paths)

**Interfaces:**
- Consumes: the `react/` package scripts (`build`, `test`).
- Produces: a CI job that runs on `react/**` changes.

- [ ] **Step 1: Create `.github/workflows/react.yml`**

```yaml
name: react

on:
  push:
    paths: ['react/**', '.github/workflows/react.yml']
  pull_request:
    paths: ['react/**', '.github/workflows/react.yml']

jobs:
  build-test:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: react
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm install
      - run: npm run typecheck
      - run: npm test
      - run: npm run build
```

- [ ] **Step 2: Guard the PHP test workflow (only if not already path-scoped)**

Inspect `.github/workflows/run-tests.yml`. If its `on:` triggers are not already path-filtered, add `paths-ignore: ['react/**']` to its `push`/`pull_request` so a `react/**`-only change doesn't run the PHP suite. If it already scopes paths, make no change.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/react.yml .github/workflows/run-tests.yml
git commit -m "ci(react): build + test workflow for the React package"
```

---

## Self-Review

**Spec coverage:**
- Package layout → Task 1. Types/config → Task 2/3. Auth headers (static/sync/async) → Task 3 (`resolveHeaders`) + Task 4 test. Columns `meta` (searchable/sortKey) → Task 2 + Task 6 query derivation. Server contract 1:1 → Task 2 `buildParams` + Task 6. Response normalization → Task 4. Column visibility → Task 6/7. Filters sheet + `filter[...]` emission → Task 2 (params) + Task 8 (UI) + Task 6 (state). Bulk actions → Task 7. States (loading/error/empty/fetching) → Task 6. Self-contained shadcn UI → Task 5. Testing (param-mapping + render/selection) → Tasks 2,3,4,6,7,8. Build & CI → Task 1 + Task 10. README + Tailwind content note + filters/sort caveats → Task 9. All spec sections covered.

**Placeholder scan:** UI-primitive vendoring (Task 5) and the README (Task 9 step 2) are described by responsibility rather than transcribed verbatim — deliberate: the shadcn sources are canonical and long, and the README is prose. Every code-bearing step in Tasks 2–4, 6–8, 10 contains complete code. No TBD/TODO/"handle edge cases" left.

**Type consistency:** `DatatableQuery`, `FilterValue`, `PaginatorResponse<T>`, `BulkAction<T>`, `ColumnMeta`, `FilterDef` are defined once in Task 2 and consumed with the same shapes in Tasks 3–8. Hook return shape (`rows/pageCount/total/isLoading/isFetching/error/refetch`) defined in Task 4 and consumed in Task 6. `Toolbar` and `FiltersSheet` prop names match between definition (Tasks 7/8) and call sites (Tasks 6/7).

**Ordering note:** Task 6's render test depends on Task 7's `Toolbar`. Implement Task 7 before running Task 6 Step 4 (flagged in that step).
