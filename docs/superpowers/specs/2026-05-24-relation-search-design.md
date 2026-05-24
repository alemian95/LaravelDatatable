# Relational Search

**Date:** 2026-05-24
**Status:** Approved (design phase)
**Scope:** Lift the existing dot-notation search limitation that drops relation columns on raw `QueryBuilder` instances, by adding a declarative `RelationSearch` API. On Eloquent builders, the existing zero-config behavior is preserved through auto-discovery.

---

## 1. Goal

Allow `search_columns=author.name` (and any `relation.column` pair) to work uniformly across Eloquent and raw `QueryBuilder` instances. Provide a small, declarative DSL — `RelationSearch::belongsTo()`, `hasOne()`, `hasMany()`, `belongsToMany()`, `custom()` — that the user declares once per relation, regardless of how many columns under that relation will be searched.

**Non-goals:**
- No multi-hop support on raw `QueryBuilder` (`book.author.country.name` requires Eloquent).
- No automatic relation introspection on raw `QueryBuilder` (no schema reflection).
- No change to flat-column search behavior.
- No change to the `SearchColumnResolver` (whitelist / auto-discovery) responsibility — it still decides *which* columns are searchable; the new code decides *how* to resolve a relation segment.
- No support for `MorphTo`, `MorphOne`, `MorphMany`, `HasOneThrough`, `HasManyThrough`. These remain unsupported with a documented warning. They can be reached via `RelationSearch::custom()` as an escape hatch.

## 2. Context

The current `SearchApplier` (`src/SearchApplier.php`) handles a dotted column with `orWhereHas($relationPath, fn ($q) => $q->whereLike(...))`. This call only works on Eloquent builders and `Relation` instances. On a raw `QueryBuilder` the dotted entries are filtered out and a `Log::warning` is emitted. This makes any search-by-relation feature unavailable to callers that pass `DB::table('books')` to `DatatableApi::fromQuery()`.

A consumer wanting to bridge this gap today must reach for `withCustomSearch()` and rewrite the entire search clause — losing the package's flat-column handling, the resolver, and the orchestration done by `SearchApplier`.

## 3. Approach

Introduce two units and modify three existing ones.

### 3.1 New: `Search\RelationSearch` (value object)

Final class. Single private constructor. Public static factories build immutable instances:

```php
RelationSearch::belongsTo(string $table, ?string $localKey = null, string $remoteKey = 'id')
RelationSearch::hasOne(string $table, ?string $foreignKey = null, string $localKey = 'id')
RelationSearch::hasMany(string $table, ?string $foreignKey = null, string $localKey = 'id')
RelationSearch::belongsToMany(
    string $table,
    string $pivot,
    ?string $foreignPivotKey = null,
    ?string $relatedPivotKey = null,
    string $parentKey = 'id',
    string $relatedKey = 'id',
)
RelationSearch::custom(\Closure $resolver)
```

Single public instance method:

```php
public function apply(Builder $query, string $baseTable, string $remoteColumn, string $term): void
```

`apply()` delegates to the captured closure built by the factory. The closure mutates `$query` with `orWhereExists(...)`. `belongsToMany` uses an inner join inside the EXISTS, not a nested EXISTS.

For each non-custom factory, `null`-defaulted keys are filled lazily inside the closure using Laravel's `Str::singular()` convention against the `$baseTable` or `$table` argument.

`custom($closure)` wraps the user closure so the internal callers always pass `$baseTable` even though the user closure receives only `($query, $remoteColumn, $term)`.

### 3.2 New: `Contracts\RelationSearchResolver` + `Search\DefaultRelationSearchResolver`

```php
interface RelationSearchResolver
{
    /** @param  array<string, RelationSearch>  $apiDeclaredMap */
    public function resolve(Builder $builder, string $relationKey, array $apiDeclaredMap): ?RelationSearch;
}
```

`DefaultRelationSearchResolver` implements a 2-source strategy with explicit precedence:

1. **Declared map wins.** If `$apiDeclaredMap[$relationKey]` exists, return it.
2. **Auto-discovery on Eloquent.** If `$builder instanceof EloquentBuilder` and `$builder->getModel()` has a method matching `$relationKey`, invoke it. Match on the returned `Relation` type:
   - `BelongsTo` → `RelationSearch::belongsTo(table, localKey=getForeignKeyName(), remoteKey=getOwnerKeyName())`
   - `HasOne` → `RelationSearch::hasOne(table, foreignKey=getForeignKeyName(), localKey=getLocalKeyName())`
   - `HasMany` → `RelationSearch::hasMany(...)` analogous
   - `BelongsToMany` → `RelationSearch::belongsToMany(table, pivot=getTable(), foreignPivotKey=getForeignPivotKeyName(), relatedPivotKey=getRelatedPivotKeyName(), parentKey=getParentKeyName(), relatedKey=getRelatedKeyName())`
   - Any other relation type → `null` (caller drops with warning).
3. **Anything else** → `null`.

The resolver reads keys from the Laravel `Relation` API directly (`getForeignKeyName()` etc.), so a model with custom keys is handled correctly without user declaration.

Registered in `DatatableServiceProvider` as `scoped`, same pattern already used for `SearchColumnResolver`.

### 3.3 Base-table fallback

`SearchApplier` reads the base table from `$builder->getModel()->getTable()` (Eloquent) or `$builder->from` (raw). When `$builder->from` is not a plain string identifier (e.g. a subquery), the base table cannot be inferred. In that case the applier drops every dotted column and emits a single `Log::warning` naming the dropped columns and the reason — same pattern as the existing dot-drop behavior, no new exception type.

`RelationSearch::custom()` does not depend on a base table (it receives only `query`, `remoteColumn`, `term`), but is dropped along with the others when base-table inference fails — keeps the contract uniform across specs and avoids partial application.

### 3.4 Modified: `SearchApplier`

Constructor gains two parameters:

```php
public function __construct(
    protected SearchColumnResolver $resolver,
    protected RelationSearchResolver $relationResolver,           // NEW
    protected ?\Closure $customSearch = null,
    protected ?array $apiDeclaredColumns = null,
    protected array $relationSearchMap = [],                       // NEW
) {}
```

`dropUnsupportedRelationColumns()` is reworked: instead of asking "is the builder Eloquent?", it asks the resolver "do you have a spec for this relation segment?". A dotted column is supported if either:
- the resolver returns non-null for the first segment, **or**
- the builder is Eloquent **and** the path is multi-hop **and** no declared spec exists for the first segment — in which case the legacy `orWhereHas` path stays (preserves existing Eloquent multi-hop behavior).

The WHERE construction loop branches identically:
- Flat column → `orWhereLike`
- Multi-hop Eloquent without declaration → `orWhereHas($relationPath, ...)` (legacy path, unchanged)
- Otherwise (single-hop, or any path with a declared spec) → resolver returns a `RelationSearch`, applier calls `$spec->apply($query, $baseTable, $remoteColumn, $term)`.

Base table is read from `$builder->getModel()->getTable()` (Eloquent) or `$builder->from` (raw). If neither is a usable string identifier, the applier drops all dotted columns with a warning (see §3.3).

### 3.5 Modified: `DatatableApi`

```php
/** @var array<string, RelationSearch> */
protected array $relationSearchMap = [];

/**
 * @param  array<string, RelationSearch>  $map
 * @return $this
 */
public function withRelationSearch(array $map): self
{
    $this->relationSearchMap = $map;
    return $this;
}
```

The map is passed to `SearchApplier` at `jsonSerialize()` time, alongside the existing arguments.

### 3.6 Modified: `DatatableServiceProvider`

Add a `scoped` binding for `RelationSearchResolver` → `DefaultRelationSearchResolver`. No state in the resolver, mirrors `SearchColumnResolver` registration.

## 4. Public-API examples

### 4.1 Eloquent, zero config (BC)

```php
return new DatatableApi()
    ->fromQuery(Book::query())
    ->withSearchableColumns(['title', 'author.name', 'tags.label']);
```

`author` auto-discovered from `Book::author()` (BelongsTo). `tags` auto-discovered from `Book::tags()` (BelongsToMany).

### 4.2 Raw QueryBuilder, Laravel conventions

```php
return new DatatableApi()
    ->fromQuery(DB::table('books'))
    ->withSearchableColumns(['title', 'author.name'])
    ->withRelationSearch([
        'author' => RelationSearch::belongsTo('authors'),
    ]);
```

Smart defaults: `localKey='author_id'`, `remoteKey='id'`, base table inferred from `$builder->from`.

### 4.3 Raw + non-conventional schema

```php
->withRelationSearch([
    'author'    => RelationSearch::belongsTo('writers', localKey: 'written_by', remoteKey: 'uuid'),
    'publisher' => RelationSearch::hasOne('publishers', foreignKey: 'book_isbn', localKey: 'isbn'),
])
```

### 4.4 Override of Eloquent auto-discovery (scope, soft-delete, custom subquery)

```php
->withRelationSearch([
    'author' => RelationSearch::custom(function ($query, $remoteColumn, $term) {
        $query->orWhereExists(fn ($sub) =>
            $sub->from('authors')
                ->whereColumn('authors.id', 'books.author_id')
                ->whereNull('authors.deleted_at')
                ->whereLike("authors.{$remoteColumn}", "%{$term}%")
        );
    }),
])
```

### 4.5 Multi-hop, Eloquent only

```php
->fromQuery(Book::query())
->withSearchableColumns(['title', 'author.country.name']);
```

Resolved via `orWhereHas('author.country', fn ($q) => $q->whereLike('name', '%term%'))` — legacy code path, unchanged. Same input on a raw `QueryBuilder` drops the column with a warning.

## 5. SQL emitted (canonical shapes)

`belongsTo('authors')` for `search_columns=author.name`, `search=jane`:

```sql
... and exists (
    select 1
    from authors
    where authors.id = books.author_id
      and authors.name like '%jane%'
)
```

`belongsToMany('roles', pivot: 'role_user')` for `search_columns=roles.label`:

```sql
... and exists (
    select 1
    from roles
    inner join role_user on role_user.role_id = roles.id
    where role_user.user_id = users.id
      and roles.label like '%jane%'
)
```

Both shapes always qualify columns as `table.column` to avoid ambiguity with the base table.

## 6. Backward-compatibility

| Scenario | Pre-change | Post-change |
|---|---|---|
| Eloquent + flat columns | identical | identical |
| Eloquent + single-hop `author.name` (no declaration) | `orWhereHas('author', fn → whereLike)` | `orWhereExists(...)` using `getForeignKeyName()`/`getOwnerKeyName()` |
| Eloquent + multi-hop `author.country.name` (no declaration) | `orWhereHas('author.country', ...)` | identical |
| Raw + dotted, no declaration | dropped + warning | identical |
| Raw + dotted, with declaration | not previously possible | resolved via spec |
| `withCustomSearch()` set | bypasses dot-notation logic entirely | identical |

The Eloquent single-hop shape change (`whereHas` → `whereExists`) is semantically equivalent for a LIKE filter: same row set, same cardinality, no eager-loading side effect. SQL text in logs differs — must be called out in CHANGELOG. Two existing tests in `SearchApplierTest` that assert textually on `whereHas` output need their assertions updated to the new shape; this is a test-side change, not a behavior regression.

## 7. Error handling

| Failure mode | Behavior |
|---|---|
| Dotted column, no spec, raw builder | Drop column, emit `Log::warning` naming the dropped columns. Same wording as today, augmented with "Declare via DatatableApi::withRelationSearch(...)". |
| Dotted column, multi-hop, raw builder | Drop column, emit `Log::warning` ("multi-hop relation search is Eloquent-only"). |
| Dotted column, multi-hop, Eloquent, declared spec for first segment | Spec receives `remoteColumn='country.name'`. Built-in factories pass this to `whereLike("authors.country.name", ...)` which fails at SQL level. Documented: declared specs handle single-hop only; for multi-hop on declared paths, use `custom()`. |
| Builder with non-string `$builder->from` (subquery as base) | All dotted columns dropped with a `Log::warning` naming the dropped columns and the cause; flat columns and the search term still apply normally. |
| Auto-discovery on a relation type we don't support (Morph, HasManyThrough, …) | Resolver returns `null` → drop column with warning. |

All non-fatal failures (no spec, multi-hop on raw, base table not inferrable) drop the offending column(s) with a `Log::warning` rather than throwing — matches the existing dot-drop pattern. The only existing exception, `SearchColumnsNotConfiguredException`, is unchanged and not extended by this work.

## 8. Testing strategy

Three test files: two new, one modified. Counts approximate; final count emerges from the plan.

### 8.1 `tests/Search/RelationSearchTest.php` (new, ≈12 tests)

Verifies that each factory emits the expected SQL. Pattern: build a `Builder` against the in-memory SQLite Testbench DB, call `$spec->apply($query, 'books', 'name', 'jane')`, assert on `$query->toRawSql()`.

Covers: default keys, custom keys for each factory; `hasMany` produces the same SQL as `hasOne`; `belongsToMany` with custom pivot and parent/related keys; `custom` invokes the user closure with the right arity (3 args, no `baseTable` leak); `or` semantics when combined with prior `where`; LIKE parameterization stays safe.

### 8.2 `tests/Search/DefaultRelationSearchResolverTest.php` (new, ≈10 tests)

Verifies precedence and auto-discovery. Covers: declared map wins over Eloquent introspection; raw builder without declaration returns `null`; Eloquent model without the named method returns `null`; auto-discovers each supported relation type with correct keys; respects custom foreign keys defined on the Eloquent relation; returns `null` for unsupported relation types (MorphTo, HasManyThrough).

### 8.3 `tests/SearchApplierTest.php` (modified, ≈8 new cases)

Existing cases stay green; two tests that assert on `whereHas` SQL text update their expected text to `whereExists` shape. New cases:

- Single-hop dotted on raw + declared spec → EXISTS emitted, no warning.
- Single-hop dotted on raw + no declaration → dropped + warning.
- Single-hop dotted on Eloquent + no declaration → EXISTS via auto-discovery (new path).
- Multi-hop dotted on Eloquent + no declaration → legacy `orWhereHas` (BC verification).
- Multi-hop dotted on raw → dropped + warning.
- Mixed flat + dotted columns → single nested WHERE combining `orWhereLike` and `orWhereExists`.
- `custom` spec receives the right `(remoteColumn, term)` for the search request.
- Declared map overrides Eloquent auto-discovery for the same relation key.

## 9. Documentation changes

- **README.md.** New section `### Relational search` placed right after `### Searchable columns`. Contains the five examples from §4 plus a one-line note on the multi-hop limit. The remaining `### Known limits` entry on dot-notation is shortened to: "Multi-hop dot-notation (`a.b.c`) is supported only on Eloquent builders; raw `QueryBuilder` is single-hop only." The current full text of the limit is otherwise resolved.
- **CHANGELOG.md.** New `Unreleased` entries:
  - `### Added` — `withRelationSearch(...)` and the `RelationSearch` DSL (`belongsTo`/`hasOne`/`hasMany`/`belongsToMany`/`custom`).
  - `### Changed` — Eloquent single-hop dot-notation search now emits `whereExists(...)` instead of `whereHas(...)`. Semantically equivalent (same rows, no eager-loading side effect); SQL text in logs changes.

## 10. Out of scope

- Multi-hop on raw `QueryBuilder` (would require nested EXISTS generation; deferred).
- Morphic relations and `*Through` relations (covered by `custom()` escape hatch).
- Sorting via declared relations (separate concern from search; `SortApplier` has its own limit documented).
- Auto-discovery of foreign keys via DB schema introspection on raw builders (no schema reflection in scope).
- Caching of resolver decisions across requests (resolver is `scoped`, recreated per request; per-request memoization is cheap and out of scope unless profiling shows otherwise).

## 11. Open questions

None at design time. Decisions captured in §3, §6, §7. Edge cases listed in §7 all have a defined behavior.
