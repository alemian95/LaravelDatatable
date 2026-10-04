# How peer packages model client filters, sorts and whitelists

Research for issue #21 (child of map #20). Facts only; the build-or-adopt decision belongs to #22.
Sources were read on 2026-10-04. Code citations are to the default branch or tag noted.

## 0. Baseline: this repo (v0.1.0, `main` @ 2189d68)

| Aspect | Current behavior | Where |
|---|---|---|
| Sort params | `sort_by=<col>&sort_order=asc\|desc` (single column) | `src/DatatableRequest.php` |
| Sort whitelist | `withSortableColumns([...])` is opt-in. Unset = any client column reaches `orderBy()` (legacy). Off-list keys are **dropped with `Log::warning`**, no exception. Dot sort (`author.name`) needs the whitelist and only follows `BelongsTo` via `leftJoin`. | `src/SortApplier.php` |
| Custom sorts | `withCustomSorts(['key' => fn($builder, $dir)])`, always allowed | `src/DatatableApi.php` |
| Search whitelist | `withSearchableColumns()` / model `$searchable` / auto-discovery; client `search_columns` intersected with the whitelist | `src/SearchApplier.php`, README |
| Filters (server) | `withCustomFilters([fn($builder)])`: closures get **only the builder, not the request**. The backend reads **no** `filter[...]` parameter. | `src/FilterApplier.php` |
| Filters (client) | React sends `filter[id]=value` and `filter[id][from]=…&filter[id][to]=…` (date range) | `react/src/build-params.ts`, `react/src/types.ts` |
| Builder accepted | `Illuminate\Contracts\Database\Query\Builder`, so Eloquent **and** raw Query Builder | `DatatableApi::fromQuery()` |
| Pagination | `per_page` clamped to `[1, max_per_page]` | `src/DatatableRequest.php` |

The React client already emits a `filter[...]` contract that the PHP side never consumes.

## 1. spatie/laravel-query-builder (v7.3.5, 2026-09-03)

Sources: [repo](https://github.com/spatie/laravel-query-builder) (`src/`, `config/query-builder.php`, `docs/features/filtering.md`, `docs/features/sorting.md`, `docs/requirements.md`).

### HTTP parameter shape
- Filters: `?filter[name]=john&filter[email]=gmail`. Parameter names are configurable (`config.parameters.filter`, `sort`, `include`, `fields`, `append`).
- Multi-value: comma-separated `filter[id]=1,2,3` is split into an array. The delimiter is configurable (`delimiter`), per filter too (`->delimiter()`), and splitting can be switched off (`filter_value_splitting_enabled`). `AllowedFilter::splitFilterValue()`.
- Nested arrays pass through: `QueryBuilderRequest::getFilterValue()` maps arrays recursively, so `filter[created_at][from]=…` reaches the filter as `['from' => …]`. `'true'`/`'false'` strings become booleans.
- Operators: `AllowedFilter::operator('salary', FilterOperator::GREATER_THAN)` is fixed server side. `FilterOperator::DYNAMIC` lets the client prefix the value (`filter[salary]=>3000`). Cases: `=`, `<`, `>`, `<=`, `>=`, `<>` (`src/Enums/FilterOperator.php`).
- Ranges: **no built-in between/range filter**. Use `scope` with comma args (`filter[schedule.starts_between]=2018-01-01,2018-12-31` in the docs) or a callback/custom filter.
- Sorts: `?sort=name,-street` gives multi-column sort with a `-` prefix for desc. `defaultSort()` applies only when no sort is requested.

### Declaration API
- `->allowedFilters(...)` takes strings (turned into `AllowedFilter::partial`) or `AllowedFilter` instances: `exact`, `partial` (`LIKE LOWER(%v%)`), `beginsWith`, `endsWith`, `operator`, `belongsTo`, `scope`, `callback(name, fn(Builder $q, $value, $name))`, `custom(name, Filter $invokable)`, `trashed`, `groupOr`/`groupAnd`.
- Modifiers: alias via `internalName` (`exact('name', 'user_passport_full_name')`), `->ignore(...values)`, `->default($v)`, `->nullable()`.
- `->allowedSorts(...)` takes strings (turned into `AllowedSort::field`) or `AllowedSort::field|custom|callback`, with an alias via `internalName` and `->defaultDirection()`.
- The custom `Filter` interface is `__invoke(Eloquent\Builder $query, mixed $value, string $property): void` (`src/Filters/Filter.php`).

### Unknown / invalid keys
- An unknown filter throws `InvalidFilterQuery` and an unknown sort throws `InvalidSortQuery`. Both extend `InvalidQuery extends HttpException` with **HTTP 400** and list the allowed names in the message (`src/Exceptions/`).
- Config `disable_invalid_filter_query_exception` / `disable_invalid_sort_query_exception` turns the exception off. The unknown key is then **silently ignored**, never applied ("does **not** allow using any filter, it just disables the exception").
- The whitelist is mandatory: only declared filters and sorts are ever applied (`FiltersQuery::addFiltersToQuery` iterates allowed filters, `SortsQuery::findSort` returns null for unknown keys).

### Relation filters
- Dot notation on `exact`, `partial` and `operator` (`posts.title`) wraps the condition in `whereHas('posts', …)`. Pass `addRelationConstraint=false` to filter a joined column instead (`HandlesRelationConstraints`).
- `belongsTo('post')` and nested `belongsTo('author_post_id', 'post.author')`. Scopes work on relations too (`schedule.starts_between`).
- Relation **sorts are not built in**. `AllowedSort` has only `field`, `custom` and `callback` (`src/Sorts/`: `SortsField`, `SortsCallback`).

### Composition with a pre-built query
- `QueryBuilder::for(EloquentBuilder|Relation|class-string $subject, ?Request $request)` wraps an existing Eloquent builder or relation. Calls such as `->paginate()` are forwarded through `__call` (`src/QueryBuilder.php`). Pagination stays the caller's job.
- **The raw `Illuminate\Database\Query\Builder` is not accepted.** Constructor, filters and sorts are typed to `Illuminate\Database\Eloquent\Builder` (`QueryBuilder::__construct`, `Filter::__invoke`, `AllowedFilter::applyTo`).
- `allowedFilters()` / `allowedSorts()` validate and apply **immediately when called**, not lazily.

## 2. Filament Tables (5.x current; 4.x docs still published)

Sources: [filters overview](https://filamentphp.com/docs/5.x/tables/filters/overview), [select filters](https://filamentphp.com/docs/5.x/tables/filters/select), [query builder filter](https://filamentphp.com/docs/5.x/tables/filters/query-builder), [custom data](https://filamentphp.com/docs/5.x/tables/custom-data), [repo `5.x`](https://github.com/filamentphp/filament/tree/5.x).

### HTTP parameter shape
- State lives in Livewire component properties, not in a REST contract. On resource list pages they are URL-bound: `#[Url(as: 'filters')] public ?array $tableFilters`, `#[Url(as: 'sort')] public ?string $tableSort`, `#[Url(as: 'search')] $tableSearch` (`packages/panels/src/Resources/Pages/ListRecords.php`). So the URL is `?filters[status][value]=draft&sort=…`. The shape is the filter's form state and is internal to Filament.
- Options: `->persistFiltersInSession()`, `->deferFilters(false)` (live filtering; the default shows an "Apply" button).

### Declaration API
- `$table->filters([...])` with `Filter::make('is_featured')->query(fn (Builder $q) => …)` (checkbox/toggle), `SelectFilter` (`options()`, `multiple()`, `attribute()`, `relationship('author', 'name')`), `TernaryFilter`, `TrashedFilter`, custom schema filters (any form fields, `->query(fn (Builder $q, array $data))`), and the `QueryBuilder` filter.
- The `QueryBuilder` filter has typed constraints (`TextConstraint`, `NumberConstraint`, `DateConstraint`, `BooleanConstraint`, `SelectConstraint`, `RelationshipConstraint`), per-constraint operators (contains, starts with, equals, is blank, is minimum, is before/after…), custom `Operator::make()`, nested AND/OR groups, and limits through `maxRules()` / `maxNestingDepth()`.
- `->baseQuery()` modifies the query outside the scoped `where` group. `->excludeWhenResolvingRecord()` is also available.
- Sorting is declared per column (`->sortable()`). The table also has a default sort.

### Unknown / invalid keys
- **Silently ignored by construction.** `HasFilters::applyFiltersToTableQuery()` iterates only the **declared** filters and reads each one's state by name, so unknown keys in `tableFilters` are never read.
- Sort: `CanSortRecords::applySortingToTableQuery()` applies a sort only if `getSortableVisibleColumn($tableSortColumn)` resolves. The direction is coerced (`=== 'desc' ? 'desc' : 'asc'`). An unknown sort is ignored with no error.
- `SelectFilter::apply()` returns early on blank values. With `multiple()` the state key is `values`, otherwise `value`.

### Relation filters
- `SelectFilter::relationship()` applies `whereHas($relation, whereIn/where key)`, optionally with `modifyRelationshipQueryUsing`, and supports an "empty relationship" option (`orWhereDoesntHave`). `RelationshipConstraint` is available in the QueryBuilder filter.

### Composition with a pre-built query
- `$table->query(Builder)` takes an Eloquent builder. Non-Eloquent sources go through `$table->records(fn (?string $sortColumn, ?string $sortDirection, ?string $search, array $filters, int $page, int $recordsPerPage) => …)`, where the developer applies everything. The docs show no direct raw `Query\Builder` support.
- Coupling: Livewire 4 + Filament forms/support (`filament/tables` requires `filament/actions`, `forms`, `query-builder`, `support`; `filament/support` requires `livewire/livewire ^4.4.2`, `illuminate/contracts ^11.28|^12|^13`, `kirschbaum-development/eloquent-power-joins` and others). It cannot be adopted as a backend for an Inertia/React JSON endpoint. It is a reference model only.

## 3. TanStack Table (v8, the version this repo's React package uses, `@tanstack/react-table ^8`)

Sources: `docs/guide/column-filtering.md` and `docs/guide/sorting.md` at tag [`v8.21.3`](https://github.com/TanStack/table/tree/v8.21.3), `packages/table-core/src/filterFns.ts`. The tanstack.com docs returned HTTP 500 at fetch time. `main` is now v9.x.

- State shapes: `ColumnFiltersState = { id: string; value: unknown }[]` and `SortingState = { id: string; desc: boolean }[]`. Both are arrays, so multi-filter and multi-sort are native.
- Server-side: with `manualFiltering: true` / `manualSorting: true` the table applies no logic and uses `data` as-is. This repo sets both (`react/src/data-table.tsx`). TanStack defines **no wire format**: serializing state to HTTP is the app's job. This repo does it in `build-params.ts`, and today sends only `sorting[0]`.
- Client "whitelist": `enableColumnFilter` / `enableColumnFilters` / `enableFilters` and `enableSorting` drive `column.getCanFilter()` / `getCanSort()`. These are UI affordances, not a security boundary.
- Built-in client filter fns (operator vocabulary): `includesString`, `includesStringSensitive`, `equalsString`, `equalsStringSensitive`, `arrIncludes`, `arrIncludesAll`, `arrIncludesSome`, `equals`, `weakEquals`, `inNumberRange` (value `[min, max]`). Custom `filterFn` with `resolveFilterValue` / `autoRemove` hooks.
- Multi-sort: `enableMultiSort`, `isMultiSortEvent`, `maxMultiSortColCount`.

## 4. Side-by-side

| | spatie/laravel-query-builder | Filament Tables | TanStack (client) | This repo today |
|---|---|---|---|---|
| Filter param | `filter[k]=a,b` | `filters[k][value]` (Livewire URL state) | none (app-defined) | client sends `filter[k]`, `filter[k][from\|to]`; server ignores it |
| Sort param | `sort=a,-b` (multi) | `sort=col:asc\|desc` (Livewire URL state, `CanSortRecords`), single | `{id, desc}[]` | `sort_by` + `sort_order`, single |
| Operators | fixed per filter, or client prefix via DYNAMIC | per-constraint operators in the QueryBuilder filter | filterFn names (client only) | none |
| Ranges | no built-in; scope/callback | DateConstraint / NumberConstraint, custom form | `inNumberRange` | client `from/to`, no server |
| Whitelist | mandatory | implicit (declared filters/columns only) | UI flags only | opt-in for sort, layered for search |
| Unknown key | **400 exception** (configurable to ignore) | ignored | n/a | sort: dropped + log warning |
| Relation filter | dot gives `whereHas`; `belongsTo` | `SelectFilter::relationship`, RelationshipConstraint | n/a | search: dot via resolver; sort: BelongsTo join |
| Custom filter | `callback`, `custom` (invokable), `scope` | `->query(fn($q, $data))`, custom schema | custom `filterFn` | closures without request access |
| Pre-built query | Eloquent Builder / Relation only | Eloquent `query()`, or `records()` | n/a | Eloquent + raw Query Builder |

## 5. Facts for build-or-adopt (#22)

Can `spatie/laravel-query-builder` run *under* `DatatableApi`?

- **License**: MIT (`composer.json`, GitHub license API). Compatible with this repo's MIT.
- **Laravel 12/13**: yes. Requires `php ^8.3`, `illuminate/database|http|support ^12.0|^13.0`, the same range as this repo (`illuminate/contracts ^12.0||^13.0`, `php ^8.3`). Only the latest major is maintained (`docs/requirements.md`).
- **Raw QueryBuilder**: **not supported**. Everything is typed to `Eloquent\Builder|Relation`. `DatatableApi::fromQuery()` currently accepts raw `Query\Builder`, so adopting it would mean either dropping raw support for filters and sorts or keeping a parallel in-house path for raw builders.
- **Dependency weight**: light. Runtime deps are `illuminate/database`, `illuminate/http`, `illuminate/support` and `spatie/laravel-package-tools ^1.11` (already a dependency here, `^1.16`). It ships a config file (`config/query-builder.php`) and a service provider.
- **Contract friction with the current API**:
  - Sort param: spatie uses `sort=-col` (multi). This repo uses `sort_by` + `sort_order`. Both param names are configurable in spatie, but the `-` prefix format is not.
  - Unknown-key policy: spatie defaults to HTTP 400. This repo drops with a log warning. The config can switch spatie to ignore.
  - The `filter[k][from]/[to]` nested shape is passed through to callback/custom filters as an array. No built-in range filter exists.
  - spatie does not paginate, search, or do relation sorts. `search`/`search_columns`, `per_page` clamping and BelongsTo sort joins would remain in-house.
  - `allowedFilters()` / `allowedSorts()` apply eagerly on call, which interacts with applier ordering in `DatatableApi::jsonSerialize()`.
  - spatie reads the request from the container (`app(QueryBuilderRequest::class)`) or an explicit `Request`. This repo builds `DatatableRequest` from `request()`.
- **Activity**: 4.4k stars, last release 7.3.5 on 2026-09-03.

Filament is not a candidate to run underneath (Livewire-bound). TanStack defines no server contract. Both are reference designs.
