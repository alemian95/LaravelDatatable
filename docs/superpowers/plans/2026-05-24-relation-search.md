# Relational Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `DatatableApi::withRelationSearch(...)` + the `RelationSearch` DSL so dotted search columns (`search_columns=author.name`) work on both Eloquent and raw `QueryBuilder` instances, with Eloquent zero-config preserved via auto-discovery.

**Architecture:** New immutable value object `RelationSearch` exposes factories (`belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, `custom`) that capture a closure emitting `orWhereExists(...)`. A `RelationSearchResolver` strategy decides per-segment which `RelationSearch` to use — declared map wins, Eloquent auto-discovery as fallback, `null` otherwise. `SearchApplier` is extended to call the resolver instead of branching on builder type, with the multi-hop Eloquent `orWhereHas` path preserved as a legacy branch for BC.

**Tech Stack:** PHP 8.4, Laravel ^11||^12||^13, Pest 4, Mockery, Orchestra Testbench, SQLite (in-memory test DB).

**Spec:** `docs/superpowers/specs/2026-05-24-relation-search-design.md`

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `src/Search/RelationSearch.php` | Create | Immutable value object with factories building EXISTS-emitting closures. |
| `src/Contracts/RelationSearchResolver.php` | Create | Strategy interface: `resolve(Builder, string $relationKey, array $map): ?RelationSearch`. |
| `src/Search/DefaultRelationSearchResolver.php` | Create | Concrete resolver: declared map → Eloquent auto-discovery → null. |
| `src/SearchApplier.php` | Modify | Wire resolver, use it for single-hop dotted columns; preserve legacy multi-hop Eloquent path; add base-table fallback. |
| `src/DatatableApi.php` | Modify | Add `withRelationSearch(array)` fluent method; pass map to applier. |
| `src/DatatableServiceProvider.php` | Modify | Register `RelationSearchResolver` as `scoped`. |
| `tests/Search/RelationSearchTest.php` | Create | Unit tests for each factory: SQL output, custom keys, parameterization. |
| `tests/Search/DefaultRelationSearchResolverTest.php` | Create | Unit tests for precedence + auto-discovery of each supported relation type. |
| `tests/SearchApplierTest.php` | Modify | Update one whereHas assertion to whereExists; add new cases for dotted+declared, dotted+auto-discovery, multi-hop preservation, base-table fallback. |
| `README.md` | Modify | New `### Relational search` section after `### Searchable columns`; shorten the residual `### Known limits` entry. |
| `CHANGELOG.md` | Modify | Add `Unreleased` entries under `### Added` and `### Changed`. |

No new migrations, no new fixture models — the existing `TestUser` (hasMany posts) + `TestPost` (belongsTo author via `test_user_id`) cover the integration cases. Other relation types are tested at the unit level with Mockery or with raw `DB::table()` queries that only need to compile to SQL, not execute.

---

## Task 1: RelationSearch — value object skeleton + `belongsTo` factory

**Files:**
- Create: `src/Search/RelationSearch.php`
- Create: `tests/Search/RelationSearchTest.php`

- [ ] **Step 1: Write the failing test (belongsTo default keys)**

Create `tests/Search/RelationSearchTest.php`:

```php
<?php

use AleMian95\Datatable\Search\RelationSearch;
use Illuminate\Support\Facades\DB;

it('belongsTo applies orWhereExists with default Laravel keys', function () {
    $query = DB::table('books');
    $spec = RelationSearch::belongsTo('authors');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'books', 'name', 'jane');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('exists')
        ->toContain('from "authors"')
        ->toContain('"authors"."id" = "books"."author_id"')
        ->toContain('"authors"."name"')
        ->toContain("'%jane%'");
});

it('belongsTo respects custom localKey and remoteKey', function () {
    $query = DB::table('books');
    $spec = RelationSearch::belongsTo('writers', localKey: 'written_by', remoteKey: 'uuid');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'books', 'name', 'jane');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('"writers"."uuid" = "books"."written_by"')
        ->toContain('"writers"."name"');
});

it('belongsTo binds the search term as a parameter (no SQL injection on apostrophes)', function () {
    $query = DB::table('books');
    $spec = RelationSearch::belongsTo('authors');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'books', 'name', "o'brien");
    });

    expect($query->getBindings())->toContain("%o'brien%");
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Search/RelationSearchTest.php`
Expected: 3 failures — `Class "AleMian95\Datatable\Search\RelationSearch" not found`.

- [ ] **Step 3: Implement RelationSearch with belongsTo only**

Create `src/Search/RelationSearch.php`:

```php
<?php

namespace AleMian95\Datatable\Search;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;

final class RelationSearch
{
    private function __construct(
        private readonly \Closure $applier,
    ) {}

    public static function belongsTo(
        string $table,
        ?string $localKey = null,
        string $remoteKey = 'id',
    ): self {
        return new self(function (Builder $query, string $baseTable, string $remoteColumn, string $term)
            use ($table, $localKey, $remoteKey): void {
            $localKey ??= Str::singular($table) . '_id';

            $query->orWhereExists(fn ($sub) =>
                $sub->from($table)
                    ->whereColumn("{$table}.{$remoteKey}", "{$baseTable}.{$localKey}")
                    ->whereLike("{$table}.{$remoteColumn}", "%{$term}%")
            );
        });
    }

    public function apply(Builder $query, string $baseTable, string $remoteColumn, string $term): void
    {
        ($this->applier)($query, $baseTable, $remoteColumn, $term);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Search/RelationSearchTest.php`
Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Search/RelationSearch.php tests/Search/RelationSearchTest.php
git commit -m "$(cat <<'EOF'
feat: add RelationSearch value object with belongsTo factory

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `hasOne` and `hasMany` factories

**Files:**
- Modify: `src/Search/RelationSearch.php`
- Modify: `tests/Search/RelationSearchTest.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/Search/RelationSearchTest.php`:

```php
it('hasOne applies EXISTS with default foreignKey derived from baseTable', function () {
    $query = DB::table('users');
    $spec = RelationSearch::hasOne('profiles');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'bio', 'jane');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('from "profiles"')
        ->toContain('"profiles"."user_id" = "users"."id"')
        ->toContain('"profiles"."bio"');
});

it('hasOne respects custom foreignKey and localKey', function () {
    $query = DB::table('users');
    $spec = RelationSearch::hasOne('profiles', foreignKey: 'u_id', localKey: 'uuid');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'bio', 'jane');
    });

    expect($query->toRawSql())
        ->toContain('"profiles"."u_id" = "users"."uuid"');
});

it('hasMany produces the same SQL shape as hasOne', function () {
    $hasOneQuery = DB::table('users');
    $hasOneQuery->where(fn ($q) =>
        RelationSearch::hasOne('posts')->apply($q, 'users', 'title', 'jane')
    );

    $hasManyQuery = DB::table('users');
    $hasManyQuery->where(fn ($q) =>
        RelationSearch::hasMany('posts')->apply($q, 'users', 'title', 'jane')
    );

    expect($hasManyQuery->toRawSql())->toBe($hasOneQuery->toRawSql());
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Search/RelationSearchTest.php --filter="hasOne|hasMany"`
Expected: 3 failures — methods don't exist.

- [ ] **Step 3: Add factories to RelationSearch**

Add to `src/Search/RelationSearch.php` after `belongsTo()`:

```php
public static function hasOne(
    string $table,
    ?string $foreignKey = null,
    string $localKey = 'id',
): self {
    return new self(function (Builder $query, string $baseTable, string $remoteColumn, string $term)
        use ($table, $foreignKey, $localKey): void {
        $foreignKey ??= Str::singular($baseTable) . '_id';

        $query->orWhereExists(fn ($sub) =>
            $sub->from($table)
                ->whereColumn("{$table}.{$foreignKey}", "{$baseTable}.{$localKey}")
                ->whereLike("{$table}.{$remoteColumn}", "%{$term}%")
        );
    });
}

public static function hasMany(
    string $table,
    ?string $foreignKey = null,
    string $localKey = 'id',
): self {
    return self::hasOne($table, $foreignKey, $localKey);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Search/RelationSearchTest.php`
Expected: 6 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Search/RelationSearch.php tests/Search/RelationSearchTest.php
git commit -m "$(cat <<'EOF'
feat: add hasOne and hasMany factories to RelationSearch

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `belongsToMany` factory

**Files:**
- Modify: `src/Search/RelationSearch.php`
- Modify: `tests/Search/RelationSearchTest.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/Search/RelationSearchTest.php`:

```php
it('belongsToMany applies EXISTS with pivot inner join and default keys', function () {
    $query = DB::table('users');
    $spec = RelationSearch::belongsToMany('roles', pivot: 'role_user');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'label', 'admin');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('from "roles"')
        ->toContain('inner join "role_user"')
        ->toContain('"role_user"."role_id" = "roles"."id"')
        ->toContain('"role_user"."user_id" = "users"."id"')
        ->toContain('"roles"."label"');
});

it('belongsToMany respects custom pivot key overrides', function () {
    $query = DB::table('users');
    $spec = RelationSearch::belongsToMany(
        'roles',
        pivot: 'role_user',
        foreignPivotKey: 'u_id',
        relatedPivotKey: 'r_id',
    );

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'label', 'admin');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('"role_user"."r_id" = "roles"."id"')
        ->toContain('"role_user"."u_id" = "users"."id"');
});

it('belongsToMany respects custom parentKey and relatedKey', function () {
    $query = DB::table('users');
    $spec = RelationSearch::belongsToMany(
        'roles',
        pivot: 'role_user',
        parentKey: 'uuid',
        relatedKey: 'slug',
    );

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'label', 'admin');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('"role_user"."role_id" = "roles"."slug"')
        ->toContain('"role_user"."user_id" = "users"."uuid"');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Search/RelationSearchTest.php --filter="belongsToMany"`
Expected: 3 failures — method doesn't exist.

- [ ] **Step 3: Add the factory**

Add to `src/Search/RelationSearch.php`:

```php
public static function belongsToMany(
    string $table,
    string $pivot,
    ?string $foreignPivotKey = null,
    ?string $relatedPivotKey = null,
    string $parentKey = 'id',
    string $relatedKey = 'id',
): self {
    return new self(function (Builder $query, string $baseTable, string $remoteColumn, string $term)
        use ($table, $pivot, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey): void {
        $foreignPivotKey ??= Str::singular($baseTable) . '_id';
        $relatedPivotKey ??= Str::singular($table) . '_id';

        $query->orWhereExists(fn ($sub) =>
            $sub->from($table)
                ->join($pivot, "{$pivot}.{$relatedPivotKey}", '=', "{$table}.{$relatedKey}")
                ->whereColumn("{$pivot}.{$foreignPivotKey}", "{$baseTable}.{$parentKey}")
                ->whereLike("{$table}.{$remoteColumn}", "%{$term}%")
        );
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Search/RelationSearchTest.php`
Expected: 9 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Search/RelationSearch.php tests/Search/RelationSearchTest.php
git commit -m "$(cat <<'EOF'
feat: add belongsToMany factory to RelationSearch

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `custom` factory (escape hatch)

**Files:**
- Modify: `src/Search/RelationSearch.php`
- Modify: `tests/Search/RelationSearchTest.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/Search/RelationSearchTest.php`:

```php
it('custom invokes the user closure with 3 args (query, remoteColumn, term)', function () {
    $received = null;
    $spec = RelationSearch::custom(function (...$args) use (&$received) {
        $received = $args;
    });

    $query = DB::table('books');
    $spec->apply($query, 'books', 'name', 'jane');

    expect($received)->toHaveCount(3);
    expect($received[1])->toBe('name');
    expect($received[2])->toBe('jane');
});

it('custom does not pass baseTable to the user closure', function () {
    $argCount = null;
    $spec = RelationSearch::custom(function ($query, $remoteColumn, $term) use (&$argCount) {
        $argCount = func_num_args();
    });

    $spec->apply(DB::table('books'), 'books', 'name', 'jane');

    expect($argCount)->toBe(3);
});

it('custom allows the user closure to mutate the query freely', function () {
    $spec = RelationSearch::custom(function ($query, $remoteColumn, $term) {
        $query->orWhere('static_marker', 'set-by-custom');
    });

    $query = DB::table('books');
    $query->where(fn ($q) => $spec->apply($q, 'books', 'name', 'jane'));

    expect($query->toRawSql())->toContain("'set-by-custom'");
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Search/RelationSearchTest.php --filter="custom"`
Expected: 3 failures — method doesn't exist.

- [ ] **Step 3: Add the factory**

Add to `src/Search/RelationSearch.php`:

```php
public static function custom(\Closure $resolver): self
{
    return new self(fn (Builder $query, string $baseTable, string $remoteColumn, string $term)
        => $resolver($query, $remoteColumn, $term));
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Search/RelationSearchTest.php`
Expected: 12 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Search/RelationSearch.php tests/Search/RelationSearchTest.php
git commit -m "$(cat <<'EOF'
feat: add custom factory to RelationSearch as escape hatch

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `RelationSearchResolver` contract + `DefaultRelationSearchResolver` (map precedence only)

**Files:**
- Create: `src/Contracts/RelationSearchResolver.php`
- Create: `src/Search/DefaultRelationSearchResolver.php`
- Create: `tests/Search/DefaultRelationSearchResolverTest.php`

- [ ] **Step 1: Write failing tests for map precedence**

Create `tests/Search/DefaultRelationSearchResolverTest.php`:

```php
<?php

use AleMian95\Datatable\Search\DefaultRelationSearchResolver;
use AleMian95\Datatable\Search\RelationSearch;
use AleMian95\Datatable\Tests\Fixtures\Models\TestPost;
use Illuminate\Support\Facades\DB;

it('returns the declared spec when the map contains the relation key', function () {
    $resolver = new DefaultRelationSearchResolver;
    $spec = RelationSearch::belongsTo('authors');

    $result = $resolver->resolve(
        DB::table('books'),
        'author',
        ['author' => $spec],
    );

    expect($result)->toBe($spec);
});

it('returns null on raw QueryBuilder when the map is empty', function () {
    $resolver = new DefaultRelationSearchResolver;

    $result = $resolver->resolve(DB::table('books'), 'author', []);

    expect($result)->toBeNull();
});

it('returns null on Eloquent when the relation method does not exist', function () {
    $resolver = new DefaultRelationSearchResolver;

    $result = $resolver->resolve(TestPost::query(), 'nonexistent', []);

    expect($result)->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Search/DefaultRelationSearchResolverTest.php`
Expected: 3 failures — `Class "AleMian95\Datatable\Search\DefaultRelationSearchResolver" not found`.

- [ ] **Step 3: Create the contract**

Create `src/Contracts/RelationSearchResolver.php`:

```php
<?php

namespace AleMian95\Datatable\Contracts;

use AleMian95\Datatable\Search\RelationSearch;
use Illuminate\Contracts\Database\Query\Builder;

interface RelationSearchResolver
{
    /**
     * Resolve which RelationSearch handles a given relation segment for this builder,
     * or null if no source can satisfy it (caller drops the dotted column + logs warning).
     *
     * @param  array<string, RelationSearch>  $apiDeclaredMap
     */
    public function resolve(Builder $builder, string $relationKey, array $apiDeclaredMap): ?RelationSearch;
}
```

- [ ] **Step 4: Create the default impl (map precedence only)**

Create `src/Search/DefaultRelationSearchResolver.php`:

```php
<?php

namespace AleMian95\Datatable\Search;

use AleMian95\Datatable\Contracts\RelationSearchResolver as Contract;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class DefaultRelationSearchResolver implements Contract
{
    public function resolve(Builder $builder, string $relationKey, array $apiDeclaredMap): ?RelationSearch
    {
        if (isset($apiDeclaredMap[$relationKey])) {
            return $apiDeclaredMap[$relationKey];
        }

        if (! $builder instanceof EloquentBuilder) {
            return null;
        }

        $model = $builder->getModel();

        if (! method_exists($model, $relationKey)) {
            return null;
        }

        // Auto-discovery for relation types added in Task 6.
        return null;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Search/DefaultRelationSearchResolverTest.php`
Expected: 3 passed.

- [ ] **Step 6: Commit**

```bash
git add src/Contracts/RelationSearchResolver.php src/Search/DefaultRelationSearchResolver.php tests/Search/DefaultRelationSearchResolverTest.php
git commit -m "$(cat <<'EOF'
feat: add RelationSearchResolver contract and default impl with map precedence

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Eloquent auto-discovery in `DefaultRelationSearchResolver`

**Files:**
- Modify: `src/Search/DefaultRelationSearchResolver.php`
- Modify: `tests/Search/DefaultRelationSearchResolverTest.php`

- [ ] **Step 1: Write failing tests for each supported relation type**

Append to `tests/Search/DefaultRelationSearchResolverTest.php`:

```php
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;

it('auto-discovers a BelongsTo relation on Eloquent', function () {
    // TestPost::author() returns belongsTo(TestUser::class, 'test_user_id')
    $resolver = new DefaultRelationSearchResolver;

    $spec = $resolver->resolve(TestPost::query(), 'author', []);

    expect($spec)->toBeInstanceOf(RelationSearch::class);

    // Verify by applying and inspecting the SQL
    $query = TestPost::query();
    $query->where(fn ($q) => $spec->apply($q, 'test_posts', 'first_name', 'jane'));
    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('from "test_users"')
        ->toContain('"test_users"."id" = "test_posts"."test_user_id"')
        ->toContain('"test_users"."first_name"');
});

it('auto-discovers a HasMany relation on Eloquent', function () {
    // TestUser::posts() returns hasMany(TestPost::class, 'test_user_id')
    $resolver = new DefaultRelationSearchResolver;

    $spec = $resolver->resolve(TestUser::query(), 'posts', []);

    expect($spec)->toBeInstanceOf(RelationSearch::class);

    $query = TestUser::query();
    $query->where(fn ($q) => $spec->apply($q, 'test_users', 'title', 'hello'));
    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('from "test_posts"')
        ->toContain('"test_posts"."test_user_id" = "test_users"."id"')
        ->toContain('"test_posts"."title"');
});

it('explicit declaration wins over auto-discovery for the same relation key', function () {
    $override = RelationSearch::belongsTo('overridden_table');
    $resolver = new DefaultRelationSearchResolver;

    $spec = $resolver->resolve(TestPost::query(), 'author', ['author' => $override]);

    expect($spec)->toBe($override);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Search/DefaultRelationSearchResolverTest.php --filter="auto-discovers|wins over"`
Expected: 3 failures — the BelongsTo and HasMany cases get `null`, the override case passes (already covered by Task 5 logic — re-runs green).

- [ ] **Step 3: Add auto-discovery for all four supported relation types**

Replace the body of `DefaultRelationSearchResolver::resolve()` after the `method_exists` guard. Final file:

```php
<?php

namespace AleMian95\Datatable\Search;

use AleMian95\Datatable\Contracts\RelationSearchResolver as Contract;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DefaultRelationSearchResolver implements Contract
{
    public function resolve(Builder $builder, string $relationKey, array $apiDeclaredMap): ?RelationSearch
    {
        if (isset($apiDeclaredMap[$relationKey])) {
            return $apiDeclaredMap[$relationKey];
        }

        if (! $builder instanceof EloquentBuilder) {
            return null;
        }

        $model = $builder->getModel();

        if (! method_exists($model, $relationKey)) {
            return null;
        }

        $relation = $model->{$relationKey}();

        return match (true) {
            $relation instanceof BelongsTo     => RelationSearch::belongsTo(
                table: $relation->getRelated()->getTable(),
                localKey: $relation->getForeignKeyName(),
                remoteKey: $relation->getOwnerKeyName(),
            ),
            $relation instanceof HasOne        => RelationSearch::hasOne(
                table: $relation->getRelated()->getTable(),
                foreignKey: $relation->getForeignKeyName(),
                localKey: $relation->getLocalKeyName(),
            ),
            $relation instanceof HasMany       => RelationSearch::hasMany(
                table: $relation->getRelated()->getTable(),
                foreignKey: $relation->getForeignKeyName(),
                localKey: $relation->getLocalKeyName(),
            ),
            $relation instanceof BelongsToMany => RelationSearch::belongsToMany(
                table: $relation->getRelated()->getTable(),
                pivot: $relation->getTable(),
                foreignPivotKey: $relation->getForeignPivotKeyName(),
                relatedPivotKey: $relation->getRelatedPivotKeyName(),
                parentKey: $relation->getParentKeyName(),
                relatedKey: $relation->getRelatedKeyName(),
            ),
            default                            => null,
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Search/DefaultRelationSearchResolverTest.php`
Expected: 6 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Search/DefaultRelationSearchResolver.php tests/Search/DefaultRelationSearchResolverTest.php
git commit -m "$(cat <<'EOF'
feat: add Eloquent auto-discovery for BelongsTo/HasOne/HasMany/BelongsToMany in DefaultRelationSearchResolver

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Register resolver in ServiceProvider + extend SearchApplier constructor (no behavior change yet)

**Files:**
- Modify: `src/DatatableServiceProvider.php`
- Modify: `src/SearchApplier.php`
- Modify: `src/DatatableApi.php`
- Modify: `tests/SearchApplierTest.php`

This task only wires the new dependency. Behavior changes are in Task 8.

- [ ] **Step 1: Register `RelationSearchResolver` as scoped**

Modify `src/DatatableServiceProvider.php` `registeringPackage()` — add at the end of the method, after the existing `SearchColumnResolver` scoped binding:

```php
$this->app->scoped(
    \AleMian95\Datatable\Contracts\RelationSearchResolver::class,
    fn () => new \AleMian95\Datatable\Search\DefaultRelationSearchResolver,
);
```

- [ ] **Step 2: Extend `SearchApplier` constructor signature**

Modify `src/SearchApplier.php` constructor:

```php
public function __construct(
    protected SearchColumnResolver $resolver,
    protected ?\Closure $customSearch = null,
    protected ?array $apiDeclaredColumns = null,
    protected ?RelationSearchResolver $relationResolver = null,
    protected array $relationSearchMap = [],
) {}
```

Add the import at the top of the file:

```php
use AleMian95\Datatable\Contracts\RelationSearchResolver;
```

The two new parameters are nullable / default-empty so existing test instantiations (`new SearchApplier($resolver)`) continue to compile. They become exercised in Task 8.

- [ ] **Step 3: Wire `withRelationSearch` into `DatatableApi`**

Modify `src/DatatableApi.php`:

Add property near the other `protected array` declarations:

```php
/** @var array<string, \AleMian95\Datatable\Search\RelationSearch> */
protected array $relationSearchMap = [];
```

Add fluent method (placed next to `withSearchableColumns`):

```php
/**
 * Declare per-relation search specs used when a search_columns entry contains a dot
 * (e.g. 'author.name'). Required for raw QueryBuilder; optional override on Eloquent.
 *
 * @param  array<string, \AleMian95\Datatable\Search\RelationSearch>  $map
 * @return $this
 */
public function withRelationSearch(array $map): self
{
    $this->relationSearchMap = $map;
    return $this;
}
```

Modify the `SearchApplier` instantiation in `jsonSerialize()`:

```php
new SearchApplier(
    app(SearchColumnResolver::class),
    $this->customSearch,
    $this->apiDeclaredSearchColumns,
    app(\AleMian95\Datatable\Contracts\RelationSearchResolver::class),
    $this->relationSearchMap,
),
```

- [ ] **Step 4: Run the existing test suite to verify nothing regressed**

Run: `vendor/bin/pest`
Expected: 48 passed (same as before this task — no behavior change yet).

- [ ] **Step 5: Commit**

```bash
git add src/DatatableServiceProvider.php src/SearchApplier.php src/DatatableApi.php
git commit -m "$(cat <<'EOF'
chore: wire RelationSearchResolver dependency into SearchApplier and DatatableApi

Adds the scoped binding, extends SearchApplier constructor with nullable
relationResolver and relationSearchMap parameters (no behavior change),
and exposes DatatableApi::withRelationSearch() that feeds the new map
through.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: SearchApplier — single-hop dotted via resolver (Eloquent auto-discovery + raw declared)

**Files:**
- Modify: `src/SearchApplier.php`
- Modify: `tests/SearchApplierTest.php`

This task replaces the dotted-column branch in `SearchApplier` so the resolver decides. Single-hop dotted columns now emit `orWhereExists` (both Eloquent auto-discovery and raw with declared spec). Multi-hop Eloquent legacy path is added in Task 9.

- [ ] **Step 1: Update the existing assertion that asserts on `orWhereHas` SQL shape**

The current test at `tests/SearchApplierTest.php` lines 132-146 (`it processes dotted entries normally on an Eloquent builder without logging a warning`) asserts on `"test_posts"` appearing in the SQL because `orWhereHas` generates an inner select on the related table. The new code emits `orWhereExists` instead — the `"test_posts"` reference is still present (the EXISTS subquery is on test_posts), so the existing string assertion still holds. **No update needed yet** — verify by re-running after Task 8 Step 4 and confirm green. If it fails, update the assertion to:

```php
expect($sql)->toContain('exists')->toContain('"test_posts"');
```

- [ ] **Step 2: Write new failing tests for the resolver-driven paths**

Append to `tests/SearchApplierTest.php`:

```php
use AleMian95\Datatable\Contracts\RelationSearchResolver;
use AleMian95\Datatable\Search\RelationSearch;
use AleMian95\Datatable\Tests\Fixtures\Models\TestPost;

it('emits orWhereExists for a single-hop dotted column on raw with a declared spec', function () {
    Log::shouldReceive('warning')->never();

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;
    $map = ['author' => RelationSearch::belongsTo('test_users', localKey: 'test_user_id')];

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, $map);
    $builder = DB::table('test_posts');

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    expect($sql)
        ->toContain('exists')
        ->toContain('"test_users"."id" = "test_posts"."test_user_id"')
        ->toContain('"test_users"."first_name"')
        ->toContain("'%jane%'");
});

it('drops a single-hop dotted column on raw when no declared spec is available', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/author\.first_name/'));

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = DB::table('test_posts');

    $beforeSql = $builder->toSql();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toSql())->toBe($beforeSql);
});

it('emits orWhereExists for a single-hop dotted column on Eloquent via auto-discovery', function () {
    Log::shouldReceive('warning')->never();

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = TestPost::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    expect($sql)
        ->toContain('exists')
        ->toContain('"test_users"."id" = "test_posts"."test_user_id"')
        ->toContain('"test_users"."first_name"');
});

it('declared map overrides Eloquent auto-discovery for the same relation key', function () {
    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;
    $overrideMarker = RelationSearch::custom(function ($query, $remoteColumn, $term) {
        $query->orWhere('manual_marker', '=', "{$remoteColumn}:{$term}");
    });

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, ['author' => $overrideMarker]);
    $builder = TestPost::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toRawSql())->toContain("'first_name:jane'");
});

it('combines a flat column and a single-hop dotted column in one nested WHERE', function () {
    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['title', 'author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = TestPost::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    expect($sql)
        ->toContain('"title" like')
        ->toContain('exists')
        ->toContain('"test_users"."first_name"');
});
```

- [ ] **Step 3: Run to verify failures**

Run: `vendor/bin/pest tests/SearchApplierTest.php`
Expected: the 5 new tests all fail — current `SearchApplier` still uses `orWhereHas` and drops on raw regardless of the map.

- [ ] **Step 4: Replace dotted-column branch in SearchApplier**

Modify `src/SearchApplier.php`. The full new file:

```php
<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use AleMian95\Datatable\Contracts\RelationSearchResolver;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\Search\RelationSearch;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

class SearchApplier implements QueryApplier
{
    /**
     * @param  array<int, string>|null  $apiDeclaredColumns
     * @param  array<string, RelationSearch>  $relationSearchMap
     */
    public function __construct(
        protected SearchColumnResolver $resolver,
        protected ?\Closure $customSearch = null,
        protected ?array $apiDeclaredColumns = null,
        protected ?RelationSearchResolver $relationResolver = null,
        protected array $relationSearchMap = [],
    ) {}

    public function apply(Builder $builder, DatatableRequest $request): void
    {
        if (! $request->hasSearch()) {
            return;
        }

        if ($this->customSearch) {
            ($this->customSearch)($builder, $request->search);
            return;
        }

        $searchColumns = $this->resolver->resolve($builder, $request, $this->apiDeclaredColumns);

        if (empty($searchColumns)) {
            return;
        }

        $resolved = $this->partitionAndResolve($builder, $searchColumns);

        if (empty($resolved['flat']) && empty($resolved['dotted'])) {
            return;
        }

        $term = $request->search;
        $baseTable = $this->baseTableFor($builder);

        $builder->where(function ($query) use ($resolved, $term, $baseTable): void {
            foreach ($resolved['flat'] as $field) {
                $query->orWhereLike($field, "%{$term}%");
            }

            foreach ($resolved['dotted'] as $entry) {
                $entry['spec']->apply($query, $baseTable, $entry['remoteColumn'], $term);
            }
        });
    }

    /**
     * @param  array<int, string>  $columns
     * @return array{flat: array<int, string>, dotted: array<int, array{spec: RelationSearch, remoteColumn: string}>}
     */
    private function partitionAndResolve(Builder $builder, array $columns): array
    {
        $flat = [];
        $dotted = [];
        $dropped = [];

        foreach ($columns as $col) {
            if (! str_contains($col, '.')) {
                $flat[] = $col;
                continue;
            }

            $segments = explode('.', $col);
            $relationKey = $segments[0];

            // Multi-hop Eloquent without explicit declaration is handled in Task 9
            // via the legacy orWhereHas branch. For now, fall through to single-hop logic.

            $spec = $this->relationResolver?->resolve($builder, $relationKey, $this->relationSearchMap);

            if ($spec === null) {
                $dropped[] = $col;
                continue;
            }

            $remoteColumn = implode('.', array_slice($segments, 1));
            $dotted[] = ['spec' => $spec, 'remoteColumn' => $remoteColumn];
        }

        if (! empty($dropped)) {
            Log::warning(sprintf(
                'SearchApplier dropped dotted columns [%s]: no RelationSearch spec found for the leading segment, and the builder is not an Eloquent builder with an auto-discoverable relation method. Declare them via DatatableApi::withRelationSearch(...).',
                implode(', ', $dropped),
            ));
        }

        return ['flat' => $flat, 'dotted' => $dotted];
    }

    private function baseTableFor(Builder $builder): string
    {
        if ($builder instanceof EloquentBuilder) {
            return $builder->getModel()->getTable();
        }

        if ($builder instanceof Relation) {
            return $builder->getRelated()->getTable();
        }

        // Raw QueryBuilder: $builder->from is typically a string identifier.
        return is_string($builder->from) ? $builder->from : '';
    }
}
```

The old `dropUnsupportedRelationColumns` method is removed; its responsibility is folded into `partitionAndResolve`.

- [ ] **Step 5: Run the full test suite**

Run: `vendor/bin/pest`
Expected: all tests pass. The previous warning message text changed, so if any pre-existing test asserted on the old text (`/ignored dot-notation columns/`), update it to match the new text (`/dropped dotted columns/`). Search:

```bash
grep -rn "ignored dot-notation" tests/
```

If found, update the regex in `Mockery::pattern(...)` to `/dropped dotted columns/`.

- [ ] **Step 6: Commit**

```bash
git add src/SearchApplier.php tests/SearchApplierTest.php
git commit -m "$(cat <<'EOF'
feat: route single-hop dotted columns through RelationSearchResolver

SearchApplier now delegates single-hop relation segments to the
resolver: Eloquent auto-discovers via the model relation, raw
QueryBuilder consults the declared withRelationSearch map. Emitted SQL
uses orWhereExists with explicit keys instead of orWhereHas.

Multi-hop Eloquent paths still fall through the resolver and are
dropped if no spec is registered — preserved in the next task.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: SearchApplier — preserve legacy multi-hop Eloquent path

**Files:**
- Modify: `src/SearchApplier.php`
- Modify: `tests/SearchApplierTest.php`

After Task 8, multi-hop Eloquent paths without a declared spec are dropped. This task restores the legacy `orWhereHas` behavior for backward compatibility.

- [ ] **Step 1: Write failing test for multi-hop Eloquent preservation**

Append to `tests/SearchApplierTest.php`:

```php
it('preserves the legacy orWhereHas path for multi-hop Eloquent without a declared spec', function () {
    Log::shouldReceive('warning')->never();

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.posts.title']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = TestPost::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    // orWhereHas('author.posts', ...) generates EXISTS on test_users joined with test_posts
    expect($sql)
        ->toContain('exists')
        ->toContain('"test_users"')
        ->toContain('"test_posts"."title"');
});

it('drops a multi-hop dotted column on raw with a warning', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/author\.posts\.title/'));

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.posts.title']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = DB::table('test_posts');

    $beforeSql = $builder->toSql();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toSql())->toBe($beforeSql);
});
```

- [ ] **Step 2: Run to verify the multi-hop Eloquent test fails**

Run: `vendor/bin/pest tests/SearchApplierTest.php --filter="multi-hop"`
Expected: the Eloquent multi-hop test fails (currently dropping the column); the raw multi-hop test passes (already covered by Task 8 dropping).

- [ ] **Step 3: Add the legacy branch in `partitionAndResolve`**

Modify `src/SearchApplier.php`. Inside the `foreach ($columns as $col)` loop in `partitionAndResolve`, immediately after the `$relationKey = $segments[0];` line, insert this block:

```php
            // Multi-hop on Eloquent without an explicit declaration: route to the
            // legacy orWhereHas path. The dotted entry is kept as-is and rendered
            // inline in apply() via a marker.
            if (
                count($segments) > 2
                && ! isset($this->relationSearchMap[$relationKey])
                && $builder instanceof EloquentBuilder
            ) {
                $dotted[] = ['legacyPath' => $col];
                continue;
            }
```

Update the array shape in the docblock to allow the legacy entry, and update the rendering loop in `apply()`:

```php
foreach ($resolved['dotted'] as $entry) {
    if (isset($entry['legacyPath'])) {
        $segments = explode('.', $entry['legacyPath']);
        $column = array_pop($segments);
        $relationPath = implode('.', $segments);
        $query->orWhereHas($relationPath, fn ($q) => $q->whereLike($column, "%{$term}%"));
        continue;
    }

    $entry['spec']->apply($query, $baseTable, $entry['remoteColumn'], $term);
}
```

Updated docblock for `partitionAndResolve`:

```php
/**
 * @param  array<int, string>  $columns
 * @return array{
 *     flat: array<int, string>,
 *     dotted: array<int, array{spec: RelationSearch, remoteColumn: string}|array{legacyPath: string}>
 * }
 */
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/SearchApplier.php tests/SearchApplierTest.php
git commit -m "$(cat <<'EOF'
feat: preserve legacy orWhereHas for multi-hop Eloquent dotted search

Multi-hop dot-notation on Eloquent without a declared spec keeps using
orWhereHas (backward-compatible). Multi-hop on raw remains dropped with
a warning, since EXISTS chaining is not in scope for v1.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: SearchApplier — base-table fallback

**Files:**
- Modify: `src/SearchApplier.php`
- Modify: `tests/SearchApplierTest.php`

When `$builder->from` is a non-string (subquery), the base table cannot be inferred; all dotted columns must be dropped with a single warning.

- [ ] **Step 1: Write failing test**

Append to `tests/SearchApplierTest.php`:

```php
it('drops dotted columns and warns when the base table cannot be inferred (subquery from)', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/cannot infer base table.*author\.first_name/i'));

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['first_name', 'author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;
    $map = ['author' => RelationSearch::belongsTo('test_users', localKey: 'test_user_id')];

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, $map);

    // Build a raw query whose `from` is a subquery — not a plain string identifier.
    $sub = DB::table('test_posts');
    $builder = DB::query()->fromSub($sub, 't');

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    // Flat column applied; dotted columns dropped (no EXISTS, no test_users)
    expect($sql)->toContain('"first_name" like');
    expect($sql)->not->toContain('exists');
    expect($sql)->not->toContain('test_users');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/SearchApplierTest.php --filter="cannot be inferred"`
Expected: failure — currently the spec would be invoked with `$baseTable=''` and emit broken SQL or partial SQL.

- [ ] **Step 3: Add base-table fallback in `partitionAndResolve`**

Modify `src/SearchApplier.php`. Compute base table upfront in `apply()`, BEFORE the partition call, and pass the result in. If empty, drop all dotted up front.

Final shape of `apply()`:

```php
public function apply(Builder $builder, DatatableRequest $request): void
{
    if (! $request->hasSearch()) {
        return;
    }

    if ($this->customSearch) {
        ($this->customSearch)($builder, $request->search);
        return;
    }

    $searchColumns = $this->resolver->resolve($builder, $request, $this->apiDeclaredColumns);

    if (empty($searchColumns)) {
        return;
    }

    $baseTable = $this->baseTableFor($builder);
    $canResolveDotted = $baseTable !== '';

    $resolved = $this->partitionAndResolve($builder, $searchColumns, $canResolveDotted);

    if (empty($resolved['flat']) && empty($resolved['dotted'])) {
        return;
    }

    $term = $request->search;

    $builder->where(function ($query) use ($resolved, $term, $baseTable): void {
        foreach ($resolved['flat'] as $field) {
            $query->orWhereLike($field, "%{$term}%");
        }

        foreach ($resolved['dotted'] as $entry) {
            if (isset($entry['legacyPath'])) {
                $segments = explode('.', $entry['legacyPath']);
                $column = array_pop($segments);
                $relationPath = implode('.', $segments);
                $query->orWhereHas($relationPath, fn ($q) => $q->whereLike($column, "%{$term}%"));
                continue;
            }

            $entry['spec']->apply($query, $baseTable, $entry['remoteColumn'], $term);
        }
    });
}
```

Update `partitionAndResolve` to take the flag and drop with a specific warning when false:

```php
/**
 * @param  array<int, string>  $columns
 * @return array{
 *     flat: array<int, string>,
 *     dotted: array<int, array{spec: RelationSearch, remoteColumn: string}|array{legacyPath: string}>
 * }
 */
private function partitionAndResolve(Builder $builder, array $columns, bool $canResolveDotted): array
{
    $flat = [];
    $dotted = [];
    $dropped = [];
    $droppedReason = '';

    foreach ($columns as $col) {
        if (! str_contains($col, '.')) {
            $flat[] = $col;
            continue;
        }

        if (! $canResolveDotted) {
            $dropped[] = $col;
            $droppedReason = 'cannot infer base table from the builder (likely a subquery passed to from()); declare keys explicitly or rebase on a plain table';
            continue;
        }

        $segments = explode('.', $col);
        $relationKey = $segments[0];

        if (
            count($segments) > 2
            && ! isset($this->relationSearchMap[$relationKey])
            && $builder instanceof EloquentBuilder
        ) {
            $dotted[] = ['legacyPath' => $col];
            continue;
        }

        $spec = $this->relationResolver?->resolve($builder, $relationKey, $this->relationSearchMap);

        if ($spec === null) {
            $dropped[] = $col;
            $droppedReason = $droppedReason !== '' ? $droppedReason : 'no RelationSearch spec found for the leading segment, and the builder is not an Eloquent builder with an auto-discoverable relation method. Declare them via DatatableApi::withRelationSearch(...)';
            continue;
        }

        $remoteColumn = implode('.', array_slice($segments, 1));
        $dotted[] = ['spec' => $spec, 'remoteColumn' => $remoteColumn];
    }

    if (! empty($dropped)) {
        Log::warning(sprintf(
            'SearchApplier dropped dotted columns [%s]: %s.',
            implode(', ', $dropped),
            $droppedReason,
        ));
    }

    return ['flat' => $flat, 'dotted' => $dotted];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/SearchApplier.php tests/SearchApplierTest.php
git commit -m "$(cat <<'EOF'
feat: drop dotted columns with warning when base table cannot be inferred

Subquery-as-from builders cannot satisfy convention-based RelationSearch
defaults. SearchApplier now drops all dotted columns up front with a
descriptive warning instead of emitting broken SQL.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Documentation — README + CHANGELOG

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add the `### Relational search` section to the README**

Modify `README.md`. Insert a new section immediately AFTER the `### Searchable columns` section and BEFORE the `### Known limits` section. Use this exact content:

````markdown
### Relational search

When `search_columns` contains a dot — `author.name`, `tags.label` — the package needs to know how to resolve the relation segment (`author`, `tags`) into SQL.

**On Eloquent builders** the relation is auto-discovered from the model. No extra configuration is needed:

```php
return new DatatableApi()
    ->fromQuery(Book::query())
    ->withSearchableColumns(['title', 'author.name', 'tags.label']);
```

Supported relation types via auto-discovery: `BelongsTo`, `HasOne`, `HasMany`, `BelongsToMany`. Other relation types (`MorphTo`, `HasManyThrough`, …) need an explicit declaration via `withRelationSearch()` using `RelationSearch::custom()`.

**On a raw `QueryBuilder`** there is no model to introspect, so the relation must be declared explicitly:

```php
use AleMian95\Datatable\Search\RelationSearch;

return new DatatableApi()
    ->fromQuery(DB::table('books'))
    ->withSearchableColumns(['title', 'author.name'])
    ->withRelationSearch([
        'author' => RelationSearch::belongsTo('authors'),
    ]);
```

Smart defaults follow the Laravel conventions: `belongsTo('authors')` assumes `author_id` and `id`. Override only when the schema diverges:

```php
->withRelationSearch([
    'author'    => RelationSearch::belongsTo('writers', localKey: 'written_by', remoteKey: 'uuid'),
    'publisher' => RelationSearch::hasOne('publishers', foreignKey: 'book_isbn', localKey: 'isbn'),
    'tags'      => RelationSearch::belongsToMany('tags', pivot: 'book_tag'),
])
```

A declared spec wins over Eloquent auto-discovery for the same relation key, which is the right tool to inject custom scopes (soft-delete filtering, tenant constraints) without rewriting the whole search:

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

**Multi-hop** dotted paths (`book.author.country.name`) are resolved automatically on Eloquent via the existing `orWhereHas` chain. On raw `QueryBuilder` multi-hop is unsupported in v1 — the column is dropped with a `Log::warning`.

**Generated SQL** uses `orWhereExists` with explicit key joins (and an inner join for `belongsToMany`). Columns are always qualified `table.column` to avoid ambiguity with the base table.
````

- [ ] **Step 2: Shorten the dot-notation entry in `### Known limits`**

Modify `README.md`. The current first entry of `### Known limits` reads:

```markdown
1. **Dot-notation search.** `search_columns=author.name` triggers `orWhereHas('author', fn ($q) => $q->whereLike('name', '%term%'))`. Works only on Eloquent builders / `Relation` instances; on a raw `QueryBuilder` the dotted entries are dropped and a `Log::warning` is emitted naming the ignored columns — useful when a query unexpectedly returns zero matches.
```

Replace it with:

```markdown
1. **Multi-hop dot-notation on raw `QueryBuilder`.** Single-hop paths (`author.name`) work on both Eloquent and raw queries — see [Relational search](#relational-search). Multi-hop paths (`author.country.name`) are supported only on Eloquent (resolved via `orWhereHas`); on a raw `QueryBuilder` they are dropped with a `Log::warning`.
```

- [ ] **Step 3: Update CHANGELOG**

Modify `CHANGELOG.md`. The current file (after Task 7's "Unreleased" entry from the L13 work) is:

```markdown
# Changelog

All notable changes to `LaravelDatatable` will be documented in this file.

## Unreleased

### Added

- Laravel 13 support: `illuminate/contracts` widened to `^11.0||^12.0||^13.0` and `orchestra/testbench` (dev) widened to `^11.0.0||^10.0.0||^9.0.0`. CI matrix now also exercises Laravel 13 paired with Testbench 11. Laravel 11 and 12 remain fully supported.
```

Append to the existing `Unreleased` section (do not create a new `Unreleased`):

```markdown
- `DatatableApi::withRelationSearch(array)` and the `RelationSearch` DSL (`belongsTo`, `hasOne`, `hasMany`, `belongsToMany`, `custom`) for declaring how to resolve dot-notation search columns. Required on raw `QueryBuilder`; optional override on Eloquent.

### Changed

- Eloquent single-hop dot-notation search now emits `orWhereExists(...)` with explicit key joins instead of `orWhereHas(...)`. Same row set, no eager-loading side effect; the SQL text in logs changes.
```

- [ ] **Step 4: Run the full test suite one more time**

Run: `vendor/bin/pest`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "$(cat <<'EOF'
docs: document relational search API and update CHANGELOG

Adds a 'Relational search' section to the README, shortens the residual
dot-notation entry in 'Known limits', and records the Added/Changed
entries in the CHANGELOG.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Definition of Done

- New units: `src/Search/RelationSearch.php`, `src/Contracts/RelationSearchResolver.php`, `src/Search/DefaultRelationSearchResolver.php`.
- Modified units: `src/SearchApplier.php`, `src/DatatableApi.php`, `src/DatatableServiceProvider.php`.
- New tests: `tests/Search/RelationSearchTest.php` (≈12), `tests/Search/DefaultRelationSearchResolverTest.php` (≈6).
- Modified tests: `tests/SearchApplierTest.php` (≈7 new cases, existing cases still green).
- Existing 48 tests still green; new tests cover the spec's §4 examples and §7 edge cases.
- Documentation: README has the new `### Relational search` section and a shortened `### Known limits` item; CHANGELOG `Unreleased` has Added + Changed entries.
- Behavior change for consumers who already use Eloquent dotted search: SQL text in logs changes from `whereHas` to `whereExists`; row set is unchanged.
- No source change required for L11/L12/L13 compat (the resolver and value object use plain Laravel surfaces).
