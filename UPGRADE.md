# Upgrade guide

## 0.1 → 0.9

0.9 is the beta of 1.0: the API below is the one 1.0 will freeze. Deprecated APIs keep working in 0.9, emit `E_USER_DEPRECATED` (Laravel logs it to the `deprecations` channel), and are removed in 1.0.

### Entry point (deprecated)

```php
// Before
return (new DatatableApi())->fromQuery(User::query());

// After
return DatatableApi::for(User::query());
```

Pass a request explicitly outside HTTP: `DatatableApi::for($query, $request)`.

### Search columns are no longer discovered by default (breaking)

`auto_discover_columns` now defaults to `false`. A search on a table with no declared columns throws `SearchColumnsNotConfiguredException`.

```php
// Declare per endpoint…
DatatableApi::for(User::query())->withSearchableColumns(['first_name', 'email']);

// …or on the model (HasSearchableColumns contract + trait, `protected array $searchable`).
```

To keep the 0.1 behaviour, publish the config and set `'auto_discover_columns' => true`.

### Sorting requires a whitelist (breaking)

Without `withSortableColumns()`, `sort_by` is ignored with a log warning. There is no switch back, because free sorting leaks the order of hidden columns.

```php
DatatableApi::for(User::query())->withSortableColumns(['created_at', 'email', 'author.name']);
```

`withCustomSorts()` keys are always sortable.

### Client filters: `withFilters()` (deprecated `withCustomFilters()`)

```php
// Before: closures read the request themselves
->withCustomFilters([
    fn ($q) => request('filter.status') ? $q->where('status', request('filter.status')) : $q,
])

// After: declared, keyed, value parsed for you
->withFilters([
    'status' => fn ($q, string $value) => $q->where('status', $value),
])
```

Fixed constraints that were never client-controlled move onto the query: `DatatableApi::for(User::where('active', true))`.

`withCustomFilters()` also changed semantics: a second call now **replaces** the first instead of adding to it. This holds for every `with*` method.

### `DatatableApi` is final (breaking)

If you extended it to share configuration, use a function instead:

```php
function usersTable(Builder $query): DatatableApi
{
    return DatatableApi::for($query)->withSearchableColumns([...])->withSortableColumns([...]);
}
```

### Internal classes

Appliers, resolvers, column sources and `DatatableRequest` are `@internal`: they may change in any release. Container bindings for `SearchColumnResolver` / `RelationSearchResolver` still work but are not a supported extension point.

### React

No changes are required. `@alemian95/laraveldatatable-react` 0.9 speaks the same HTTP contract.
