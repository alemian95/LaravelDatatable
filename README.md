# This is my package laraveldatatable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/alemian95/laraveldatatable.svg?style=flat-square)](https://packagist.org/packages/alemian95/laraveldatatable)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/alemian95/laraveldatatable/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/alemian95/laraveldatatable/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/alemian95/laraveldatatable/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/alemian95/laraveldatatable/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/alemian95/laraveldatatable.svg?style=flat-square)](https://packagist.org/packages/alemian95/laraveldatatable)

A lightweight, server-side datatable query layer for Laravel. Wrap any Eloquent or Query Builder instance and get standardized JSON pagination, search, sorting and filtering driven by HTTP request parameters — with hooks to override each step.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/LaravelDatatable.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/LaravelDatatable)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Requirements

- PHP `^8.4`
- Laravel `^11.0 || ^12.0`

## Installation

Install via Composer:

```bash
composer require alemian95/laraveldatatable
```

The service provider is auto-discovered (`AleMian95\Datatable\DatatableServiceProvider`) — no manual registration required.

Optionally, publish the config file to override defaults:

```bash
php artisan vendor:publish --tag="laraveldatatable-config"
```

This is the contents of the published `config/laraveldatatable.php`:

```php
return [
    'default' => [
        // Default page size used when the request omits "per_page".
        'per_page' => 15,
    ],

    'search' => [
        // When true: fall back to Schema introspection if no whitelist is declared.
        // When false: declaring HasSearchableColumns or withSearchableColumns() is mandatory.
        'auto_discover_columns' => true,

        // Column names / wildcard patterns always excluded from auto-discovery.
        'auto_discovery_blacklist' => [
            'password', 'remember_token', 'api_token',
            '*_token', '*_secret', '*_hash', '*_key',
        ],
    ],
];
```

## Usage

The main entry point is `AleMian95\Datatable\DatatableApi`. It implements `JsonSerializable`, so returning an instance directly from a controller produces a paginated JSON response automatically.

### Quick start

```php
use AleMian95\Datatable\DatatableApi;
use App\Models\User;

public function index()
{
    return new DatatableApi()
        ->fromQuery(User::query());
}
```

That single call already supports search, sort and pagination via the HTTP query string described below.

### HTTP request contract

`DatatableRequest` parses the following parameters from the incoming request:

| Parameter        | Type           | Default                                       | Purpose                                                               |
|------------------|----------------|-----------------------------------------------|-----------------------------------------------------------------------|
| `search`         | string         | `null`                                        | Free-text term applied with case-insensitive `LIKE %term%`.           |
| `search_columns` | csv string     | auto-resolved (see Advanced)                  | Columns to search in. Supports dot-notation `relation.column`.        |
| `sort_by`        | string         | `null`                                        | Column to sort by. Supports dot-notation for `BelongsTo` relations.   |
| `sort_order`     | `asc` \| `desc`| `asc`                                         | Sort direction.                                                       |
| `per_page`       | int            | `config('laraveldatatable.default.per_page', 15)` | Results per page.                                                 |

Example request:

```
GET /api/users?search=jane&search_columns=first_name,last_name,email&sort_by=created_at&sort_order=desc&per_page=25
```

### Response shape

Without a resource, the response is a standard Laravel length-aware paginator:

```json
{
  "current_page": 1,
  "data": [ { "id": 1, "name": "Jane Doe", "...": "..." } ],
  "first_page_url": "...",
  "from": 1,
  "last_page": 5,
  "last_page_url": "...",
  "links": [ /* ... */ ],
  "next_page_url": "...",
  "path": "...",
  "per_page": 15,
  "prev_page_url": null,
  "to": 15,
  "total": 75
}
```

When `returnResource(ResourceClass::class)` is used, the paginator is wrapped in `ResourceClass::collection($paginator)`, producing the conventional `{ "data": [...], "links": {...}, "meta": {...} }` envelope.

### Customizing the pipeline

Each builder method below returns `$this`, so they can be chained freely.

- **`fromQuery(Builder $query): self`** — accepts an Eloquent builder, a `Relation`, or a base `QueryBuilder`. Required.
- **`withCustomSearch(Closure $search): self`** — overrides the default LIKE/auto-column search. The closure receives `($builder, string $term)` and is responsible for the full search clause.
- **`withCustomSorts(array $sorts): self`** — map of `sort_by` value → `Closure($builder, string $direction)`. Triggered only when the incoming `sort_by` matches a key; otherwise the default sort logic runs.
- **`withCustomFilters(array $filters): self`** — array of `Closure($builder)` applied sequentially. Useful for hard-coded business filters (active scope, tenant scope, etc.) that should not be controllable from the client.
- **`withSearchableColumns(array $columns): self`** — declares the authoritative whitelist of columns the search can target for this instance. Wins over the `HasSearchableColumns` contract on the model and is the only way to enable search on a raw `QueryBuilder` when `auto_discover_columns` is `false`. When set, `search_columns` from the request is intersected against this whitelist.
- **`returnResource(string $resourceClass): self`** — fully-qualified API Resource class name. Output is wrapped via `Resource::collection($paginator)`.

Full chained example:

```php
use AleMian95\Datatable\DatatableApi;
use App\Http\Resources\UserResource;
use App\Models\User;

public function index()
{
    return new DatatableApi()
        ->fromQuery(
            User::query()->with('profile', 'role')
        )
        ->withCustomSorts([
            'full_name' => fn ($builder, $direction) =>
                $builder->orderByRaw("CONCAT(first_name, ' ', last_name) {$direction}"),
        ])
        ->withCustomFilters([
            fn ($builder) => $builder->where('active', true),
        ])
        ->returnResource(UserResource::class);
}
```

### Advanced & known limits

1. **Searchable columns: declarative opt-in (recommended).** The set of columns that can be searched is resolved in this order:

   1. `DatatableApi::withSearchableColumns(['col_a', 'col_b'])` — wins over everything.
   2. `Model implements HasSearchableColumns` — the contract returns the whitelist (the trait `Concerns\HasSearchableColumns` reads a `protected array $searchable = [...]` property by default).
   3. Auto-discovery via `Schema::getColumnListing` — fallback **only** when `config('laraveldatatable.search.auto_discover_columns')` is `true` (default for backward compatibility). Filters out non-string columns and applies the `auto_discovery_blacklist`. When the request supplies `search_columns` in this branch, they are intersected against the auto-discovery result — so the type filter and the blacklist also protect against client-supplied column names.

   When a whitelist is declared, `search_columns` from the HTTP request is intersected against it: the client can never broaden it. An empty whitelist (`withSearchableColumns([])` or `protected array $searchable = []`) is treated as an **authoritative "block the search" signal** — the search clause is omitted, and there is no fallback to the next source. When no source can satisfy the request and auto-discovery is off, a `SearchColumnsNotConfiguredException` is thrown.

   Example with the trait:

   ```php
   use AleMian95\Datatable\Contracts\HasSearchableColumns;
   use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;

   class User extends Model implements HasSearchableColumns
   {
       use HasSearchableColumnsTrait;

       protected array $searchable = ['first_name', 'last_name', 'email', 'profile.bio'];
   }
   ```

   Example with the per-request override (works for both Eloquent and raw `QueryBuilder`):

   ```php
   return new DatatableApi()
       ->fromQuery(DB::table('users'))
       ->withSearchableColumns(['name', 'email']);
   ```

   To make declaration mandatory project-wide, set in `config/laraveldatatable.php`:

   ```php
   'search' => [
       'auto_discover_columns' => false,
   ],
   ```

2. **Dot-notation search.** `search_columns=author.name` triggers `orWhereHas('author', fn ($q) => $q->whereLike('name', '%term%'))`. Works only on Eloquent builders / `Relation` instances; on a raw `QueryBuilder` the dotted entries are ignored.

3. **Relational sorting supports `BelongsTo` only.** For `sort_by=author.name`, `SortApplier` performs a `leftJoin` on each `BelongsTo` segment and then orders by the joined column. For any other relation type (or any segment that is not a `BelongsTo`) it falls back to a plain `orderBy('author.name', ...)`, which will fail at the SQL layer because that column does not exist on the base table. Either expose such sorts via `withCustomSorts(...)` or restrict the client to `BelongsTo` paths.

4. **SQL logging outside production.** While `app()->isProduction()` is `false`, every assembled query is written to the application log via `Log::info($builder->toRawSql())`. This is intentional for local debugging — be aware of it in staging environments where it can produce noisy logs.

5. **The `Datatable` facade is currently a no-op.** `AleMian95\Datatable\Datatable` is an empty class and its facade alias exists for forward compatibility. Always instantiate `DatatableApi` directly — `Datatable::something(...)` will not work.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Alessandro Mian](https://github.com/alemian95)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
