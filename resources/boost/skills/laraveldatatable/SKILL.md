---
name: laraveldatatable
description: Server-side datatables with alemian95/laraveldatatable and its React companion @alemian95/laraveldatatable-react. Use when building or changing a paginated, searchable, sortable or filterable list endpoint with DatatableApi, or a table page with the DataTable component.
---

<!-- PROTOTYPE (wayfinder: "Draft SKILL.md: structure, content and description", alemian95/LaravelDatatable#51). Throwaway. -->

# Laravel Datatable

One endpoint serves one table. The backend (`DatatableApi`) reads a fixed set of query parameters, the React `DataTable` sends exactly those; the two sides meet on the **contract** below, and every capability the client may use is opt-in on the backend through a **whitelist**.

## Build a table end to end

1. **Endpoint.** In the controller, return `DatatableApi::for($query)`. It is `Responsable`: Laravel turns it into paginated JSON. Put fixed constraints (tenant, `active`, eager loads) on `$query`, never in the client parameters. For an Inertia prop, call `->toPaginator()` instead.
2. **Whitelists.** Declare what the client may touch, on the same chain:
   - search: `->withSearchableColumns([...])`, or the `HasSearchableColumns` contract on the model (below);
   - sort: `->withSortableColumns([...])`, plus `->withCustomSorts([...])` for computed sorts;
   - filters: `->withFilters(['key' => fn ($q, string|array $value) => ...])`.
   Anything undeclared is ignored with a log warning and the request still succeeds: a missing whitelist entry looks like "the table ignores my search/sort/filter", not like an error.
3. **Output shape** (optional). `->returnResource(UserResource::class)` wraps the page in `{data, links, meta}`. The React side reads both shapes.
4. **Table.** Wrap the page in `DatatableProvider` (`baseUrl`, optional `headers`, `labels`) and render `<DataTable endpoint=... columns=... />`. Match every column to the backend whitelist:
   - `meta.searchable: true` only on columns in the search whitelist;
   - `enableSorting: true` only on columns in `withSortableColumns()` or `withCustomSorts()`; `meta.sortKey` when the `sort_by` value differs from the column id;
   - each `FilterDef.id` equal to a `withFilters()` key; `type: 'date-range'` filters receive `['from' => ?string, 'to' => ?string]`.

## Backend reference

```php
use AleMian95\Datatable\DatatableApi;

return DatatableApi::for(User::query()->where('active', true)->with('role'))
    ->withSearchableColumns(['name', 'email', 'role.name'])
    ->withSortableColumns(['name', 'created_at', 'role.name'])
    ->withCustomSorts([
        'full_name' => fn ($q, string $dir) => $q->orderByRaw("CONCAT(first_name, ' ', last_name) {$dir}"),
    ])
    ->withFilters([
        'status' => fn ($q, string $value) => $q->where('status', $value),
        'created_at' => fn ($q, array $r) => $q
            ->when($r['from'], fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($r['to'], fn ($q, $to) => $q->whereDate('created_at', '<=', $to)),
    ])
    ->returnResource(UserResource::class);
```

- `for()` accepts an Eloquent builder, a relation or `DB::table(...)`. Pass `$request` explicitly outside HTTP (jobs, commands, tests).
- Every `with*` call replaces the previous one of the same kind.
- `withCustomSearch(fn ($q, string $term) => ...)` replaces the whole search clause; use it for joined tables on a raw query builder.
- Typing the filter value `string` or `array` rejects the other shape before it reaches the closure.

**Searchable columns**, first match wins:
1. `withSearchableColumns([...])`;
2. the model implements `AleMian95\Datatable\Contracts\HasSearchableColumns`, usually via the trait `AleMian95\Datatable\Concerns\HasSearchableColumns` and `protected array $searchable = [...]`;
3. schema auto-discovery, only when `laraveldatatable.search.auto_discover_columns` is `true` (off by default; leave it off).

An empty whitelist disables search. The client's `search_columns` is intersected with the whitelist and can never widen it.

**Relations in dotted columns** (`author.name`):
- search on Eloquent: `BelongsTo`, `HasOne`, `HasMany`, `BelongsToMany` resolve by themselves, multi-hop included;
- search on `DB::table(...)`, or other relation types: declare `->withRelationSearch(['author' => RelationSearch::belongsTo('authors')])` (`AleMian95\Datatable\Search\RelationSearch`: `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, `custom`); single hop only;
- sort: `BelongsTo` chains only; anything else goes through `withCustomSorts()`.

**Shared setup** across endpoints: write a function or factory that returns a configured `DatatableApi`. The class is `final`.

**Config** (`config/laraveldatatable.php`): `default.per_page` (15), `default.max_per_page` (100, larger requests are clamped), `debug.log_sql`, `search.auto_discover_columns`, `search.auto_discovery_blacklist`.

## HTTP contract

| Parameter | Value |
|---|---|
| `page`, `per_page` | int |
| `search` | string, case-insensitive `LIKE %term%` |
| `search_columns` | csv, dotted entries allowed |
| `sort_by`, `sort_order` | column or custom-sort key, `asc`\|`desc` |
| `filter[<key>]` | scalar, or `filter[<key>][from]` / `[to]` |

Response: a Laravel length-aware paginator, or a resource collection (`data`, `links`, `meta`) with `returnResource()`.

## React reference

```tsx
import {
  DatatableProvider, DataTable,
  type ColumnDef, type FilterDef, type BulkAction,
} from '@alemian95/laraveldatatable-react'

const columns: ColumnDef<User, unknown>[] = [
  { accessorKey: 'name', header: 'Name', enableSorting: true, meta: { searchable: true } },
  { accessorKey: 'created_at', header: 'Created', enableSorting: true },
]
const filters: FilterDef[] = [
  { id: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Active' }] },
  { id: 'created_at', label: 'Created', type: 'date-range' },
]

<DatatableProvider config={{ baseUrl: '/api' }}>
  <DataTable<User> endpoint="/users" columns={columns} filters={filters} />
</DatatableProvider>
```

- Import column types and `createColumnHelper` from `@alemian95/laraveldatatable-react`, not from `@tanstack/react-table` (strict `node_modules` under pnpm).
- Keep `defaultPerPage` and `perPageOptions` at or below the backend `max_per_page`.
- `bulkActions` enables row selection; `getRowId` when rows have no `id`.
- Texts: `config.labels` (partial, defaults in `defaultLabels`).
- Custom UI: `useDatatable` returns `{ rows, pageCount, total, isLoading, isFetching, error, refetch }`.
- Tailwind must scan the package: v4 `@source "../../node_modules/@alemian95/laraveldatatable-react/dist";` in the CSS entry, v3 the same path in `content`. Unstyled tables mean this line is missing.

## Public API

Use only: `DatatableApi`, `Search\RelationSearch`, `Contracts\HasSearchableColumns` and `Concerns\HasSearchableColumns`, `Exceptions\SearchColumnsNotConfiguredException`, the config keys, `php artisan datatable:install`, and the exports of `@alemian95/laraveldatatable-react`. Every other class in `AleMian95\Datatable` is `@internal` and changes without notice.

## More detail

The package READMEs are installed with the code: `vendor/alemian95/laraveldatatable/README.md` (backend, known limits) and `node_modules/@alemian95/laraveldatatable-react/README.md` (component props, labels).
