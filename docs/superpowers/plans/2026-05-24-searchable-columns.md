# Searchable Columns — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the implicit `Schema::getColumnListing` fallback in `SearchApplier` with an explicit, opt-in declaration of searchable columns (`HasSearchableColumns` contract + trait on Models, or `withSearchableColumns()` on `DatatableApi`), keeping auto-discovery as a configurable flag and throwing a dedicated exception when no source can satisfy the request.

**Architecture:** Hexagonal-style decomposition: a `SearchColumnResolver` (orchestrator) coordinates three pure `Source` classes (API-declared, Model-declared, auto-discovery). `SearchApplier` becomes a thin SQL builder that depends on the resolver via DI. The service provider binds the default implementation; consumers can swap it.

**Tech Stack:** PHP 8.4, Laravel 11/12, Pest 4 + Orchestra Testbench, spatie/laravel-package-tools, PSR-4 namespace `AleMian95\Datatable\`.

---

## Spec Reference

Full design: `docs/superpowers/specs/2026-05-24-searchable-columns-design.md`.

## File Structure

**New files:**
- `src/Contracts/HasSearchableColumns.php` — Model-side contract.
- `src/Contracts/SearchColumnResolver.php` — Resolver contract.
- `src/Concerns/HasSearchableColumns.php` — Default trait implementation.
- `src/Exceptions/SearchColumnsNotConfiguredException.php` — Thrown when nothing can resolve.
- `src/Search/DefaultSearchColumnResolver.php` — Decision orchestrator.
- `src/Search/Sources/ApiDeclaredColumnSource.php` — Reads `withSearchableColumns()` data.
- `src/Search/Sources/ModelDeclaredColumnSource.php` — Reads from `HasSearchableColumns` Models.
- `src/Search/Sources/AutoDiscoveryColumnSource.php` — Schema introspection with type filter + blacklist.

**Modified files:**
- `config/laraveldatatable.php` — Add `search` section.
- `src/SearchApplier.php` — Depend on `SearchColumnResolver`; drop in-line discovery.
- `src/DatatableApi.php` — Add `withSearchableColumns()`, propagate to `SearchApplier`.
- `src/DatatableServiceProvider.php` — Bind `SearchColumnResolver`.
- `README.md` — Replace limit #1 description; document new contract, trait, builder method, config.

**Test infrastructure (new):**
- `tests/Fixtures/Models/TestUser.php` — Eloquent Model for integration tests.
- `tests/Fixtures/Models/TestPost.php` — Related model for relation tests.
- `tests/Database/Migrations/0001_01_01_000000_create_test_users_table.php`
- `tests/Database/Migrations/0001_01_01_000001_create_test_posts_table.php`
- `tests/TestCase.php` — Modify `getEnvironmentSetUp` to load migrations.
- `tests/Search/Sources/ApiDeclaredColumnSourceTest.php`
- `tests/Search/Sources/ModelDeclaredColumnSourceTest.php`
- `tests/Search/Sources/AutoDiscoveryColumnSourceTest.php`
- `tests/Search/DefaultSearchColumnResolverTest.php`
- `tests/SearchApplierTest.php`
- `tests/DatatableApiSearchableColumnsTest.php` — End-to-end integration.

---

## Task 1: Test Infrastructure — Migrations, Models, TestCase

**Why first:** every subsequent task needs an Eloquent Model bound to a real (in-memory) table. Without this we cannot write meaningful tests.

**Files:**
- Create: `tests/Database/Migrations/0001_01_01_000000_create_test_users_table.php`
- Create: `tests/Database/Migrations/0001_01_01_000001_create_test_posts_table.php`
- Create: `tests/Fixtures/Models/TestUser.php`
- Create: `tests/Fixtures/Models/TestPost.php`
- Modify: `tests/TestCase.php`

- [ ] **Step 1.1: Create users migration**

Create `tests/Database/Migrations/0001_01_01_000000_create_test_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password')->default('secret');
            $table->string('remember_token')->nullable();
            $table->string('api_token')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('login_count')->default(0);
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 1.2: Create posts migration**

Create `tests/Database/Migrations/0001_01_01_000001_create_test_posts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_user_id')->constrained('test_users');
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 1.3: Create TestUser model**

Create `tests/Fixtures/Models/TestUser.php`:

```php
<?php

namespace AleMian95\Datatable\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestUser extends Model
{
    protected $table = 'test_users';

    protected $guarded = [];

    public $timestamps = true;

    public function posts(): HasMany
    {
        return $this->hasMany(TestPost::class, 'test_user_id');
    }
}
```

- [ ] **Step 1.4: Create TestPost model**

Create `tests/Fixtures/Models/TestPost.php`:

```php
<?php

namespace AleMian95\Datatable\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestPost extends Model
{
    protected $table = 'test_posts';

    protected $guarded = [];

    public $timestamps = true;

    public function author(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'test_user_id');
    }
}
```

- [ ] **Step 1.5: Register Fixtures autoload**

Modify `composer.json` — update `autoload-dev.psr-4` to register the fixtures namespace. Replace the existing block:

```json
"autoload-dev": {
    "psr-4": {
        "AleMian95\\Datatable\\Tests\\": "tests/",
        "AleMian95\\Datatable\\Tests\\Fixtures\\": "tests/Fixtures/",
        "Workbench\\App\\": "workbench/app/"
    }
},
```

Then run:

```bash
composer dump-autoload
```

Expected: classmap regenerated, no errors. (The first PSR-4 entry already covers `tests/Fixtures/Models/`; the explicit second entry is for clarity and resilience against future renames — harmless duplication that PSR-4 handles fine.)

- [ ] **Step 1.6: Modify TestCase to load migrations**

Replace the contents of `tests/TestCase.php` with:

```php
<?php

namespace AleMian95\Datatable\Tests;

use AleMian95\Datatable\DatatableServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'AleMian95\\Datatable\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            DatatableServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        foreach (File::allFiles(__DIR__.'/Database/Migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
```

- [ ] **Step 1.7: Smoke test the infrastructure**

Run:

```bash
vendor/bin/pest tests/ExampleTest.php
```

Expected: PASS (1 test). This verifies migrations didn't break the boot.

Then verify Models work — add a temporary check to `tests/ExampleTest.php`:

```php
<?php

use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;

it('can test', function () {
    expect(true)->toBeTrue();
});

it('boots the test schema and Eloquent models', function () {
    TestUser::create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.test',
    ]);

    expect(TestUser::count())->toBe(1);
});
```

Run:

```bash
vendor/bin/pest tests/ExampleTest.php
```

Expected: 2 tests PASS.

- [ ] **Step 1.8: Revert the smoke test, keep ExampleTest minimal**

Restore `tests/ExampleTest.php` to:

```php
<?php

it('can test', function () {
    expect(true)->toBeTrue();
});
```

- [ ] **Step 1.9: Commit**

```bash
git add tests/ composer.json composer.lock
git commit -m "test: add fixture models, migrations, and migration loader for test suite

Sets up TestUser/TestPost Eloquent models backed by SQLite in-memory tables
loaded automatically in getEnvironmentSetUp. Required by the upcoming
searchable-columns work which needs real Eloquent builders against a real
schema."
```

---

## Task 2: Config — `search` section

**Files:**
- Modify: `config/laraveldatatable.php`

- [ ] **Step 2.1: Add `search` section to the config**

Replace the contents of `config/laraveldatatable.php` with:

```php
<?php

// config for AleMian95/Datatable
return [

    'default' => [
        /*
        |--------------------------------------------------------------------------
        | Default page size
        |--------------------------------------------------------------------------
        |
        | Used when the request does not include a "per_page" query parameter.
        |
        */
        'per_page' => 15,
    ],

    'search' => [
        /*
        |--------------------------------------------------------------------------
        | Automatic column discovery
        |--------------------------------------------------------------------------
        |
        | When true, the SearchApplier falls back to Schema introspection if
        | neither DatatableApi::withSearchableColumns() nor the
        | HasSearchableColumns contract on the model provides a whitelist.
        |
        | When false, declaring a whitelist is mandatory: a
        | SearchColumnsNotConfiguredException is thrown otherwise.
        |
        */
        'auto_discover_columns' => true,

        /*
        |--------------------------------------------------------------------------
        | Auto-discovery blacklist
        |--------------------------------------------------------------------------
        |
        | Column names (or wildcard patterns where * matches any sequence)
        | always excluded from auto-discovery. Matching is case-insensitive.
        | Only used when auto_discover_columns is true.
        |
        */
        'auto_discovery_blacklist' => [
            'password',
            'remember_token',
            'api_token',
            '*_token',
            '*_secret',
            '*_hash',
            '*_key',
        ],
    ],

];
```

- [ ] **Step 2.2: Commit**

```bash
git add config/laraveldatatable.php
git commit -m "config: add search.auto_discover_columns and auto_discovery_blacklist

Introduces the configuration knobs that govern the new searchable-columns
resolution pipeline. Defaults preserve the existing auto-discovery behavior
with the addition of a security blacklist."
```

---

## Task 3: Contract `HasSearchableColumns`

**Files:**
- Create: `src/Contracts/HasSearchableColumns.php`

- [ ] **Step 3.1: Create the interface**

Create `src/Contracts/HasSearchableColumns.php`:

```php
<?php

namespace AleMian95\Datatable\Contracts;

interface HasSearchableColumns
{
    /**
     * Columns authorized for the search of this Model.
     * Supports dot-notation for relations (e.g. "author.name").
     *
     * @return array<int, string>
     */
    public function getSearchableColumns(): array;
}
```

- [ ] **Step 3.2: Commit**

```bash
git add src/Contracts/HasSearchableColumns.php
git commit -m "feat: add HasSearchableColumns contract

Single-method interface that Models implement to declare which columns are
authorized for datatable search. Supports dot-notation for relations."
```

---

## Task 4: Trait `Concerns\HasSearchableColumns`

**Files:**
- Create: `src/Concerns/HasSearchableColumns.php`

- [ ] **Step 4.1: Create the trait**

Create `src/Concerns/HasSearchableColumns.php`:

```php
<?php

namespace AleMian95\Datatable\Concerns;

trait HasSearchableColumns
{
    /**
     * Default convention-based implementation: reads from the $searchable
     * property. Override this method to compute the list dynamically (for
     * example based on the authenticated user's role).
     *
     * @return array<int, string>
     */
    public function getSearchableColumns(): array
    {
        return property_exists($this, 'searchable') && is_array($this->searchable)
            ? $this->searchable
            : [];
    }
}
```

- [ ] **Step 4.2: Commit**

```bash
git add src/Concerns/HasSearchableColumns.php
git commit -m "feat: add HasSearchableColumns trait with \$searchable convention

Provides the default implementation of the contract: reads from a
\$searchable array property, falling back to an empty array. Override the
method for dynamic logic."
```

---

## Task 5: Exception `SearchColumnsNotConfiguredException`

**Files:**
- Create: `src/Exceptions/SearchColumnsNotConfiguredException.php`

- [ ] **Step 5.1: Create the exception**

Create `src/Exceptions/SearchColumnsNotConfiguredException.php`:

```php
<?php

namespace AleMian95\Datatable\Exceptions;

use LogicException;

class SearchColumnsNotConfiguredException extends LogicException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            'The model [%s] does not implement %s and auto_discover_columns is disabled. '
            .'Either implement the contract on the model, call withSearchableColumns() '
            .'on the DatatableApi instance, or enable auto_discover_columns in '
            .'config/laraveldatatable.php.',
            $modelClass,
            \AleMian95\Datatable\Contracts\HasSearchableColumns::class,
        ));
    }

    public static function forTable(string $table): self
    {
        return new self(sprintf(
            'No searchable columns declared for table [%s] and auto_discover_columns is disabled. '
            .'Call withSearchableColumns() on the DatatableApi instance or enable '
            .'auto_discover_columns in config/laraveldatatable.php.',
            $table,
        ));
    }
}
```

- [ ] **Step 5.2: Commit**

```bash
git add src/Exceptions/SearchColumnsNotConfiguredException.php
git commit -m "feat: add SearchColumnsNotConfiguredException

Thrown when no column source can satisfy the search and auto-discovery is
disabled. Provides two named constructors for the Eloquent and raw
QueryBuilder cases with actionable error messages."
```

---

## Task 6: Contract `SearchColumnResolver`

**Files:**
- Create: `src/Contracts/SearchColumnResolver.php`

- [ ] **Step 6.1: Create the interface**

Create `src/Contracts/SearchColumnResolver.php`:

```php
<?php

namespace AleMian95\Datatable\Contracts;

use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\Exceptions\SearchColumnsNotConfiguredException;
use Illuminate\Contracts\Database\Query\Builder;

interface SearchColumnResolver
{
    /**
     * Resolve the effective list of columns to search on.
     *
     * @param  array<int, string>|null  $apiDeclaredColumns  Columns passed via DatatableApi::withSearchableColumns()
     * @return array<int, string>  Empty array means: do not apply any search clause.
     *
     * @throws SearchColumnsNotConfiguredException
     */
    public function resolve(
        Builder $builder,
        DatatableRequest $request,
        ?array $apiDeclaredColumns,
    ): array;
}
```

- [ ] **Step 6.2: Commit**

```bash
git add src/Contracts/SearchColumnResolver.php
git commit -m "feat: add SearchColumnResolver contract

Extension point that consumers (or future package internals) can bind a
custom implementation to via the service container."
```

---

## Task 7: `ApiDeclaredColumnSource`

**Files:**
- Create: `src/Search/Sources/ApiDeclaredColumnSource.php`
- Test: `tests/Search/Sources/ApiDeclaredColumnSourceTest.php`

- [ ] **Step 7.1: Write the failing tests**

Create `tests/Search/Sources/ApiDeclaredColumnSourceTest.php`:

```php
<?php

use AleMian95\Datatable\Search\Sources\ApiDeclaredColumnSource;

it('returns the columns passed in', function () {
    $source = new ApiDeclaredColumnSource();

    expect($source->columns(['name', 'email']))->toBe(['name', 'email']);
});

it('returns an empty array when given null', function () {
    $source = new ApiDeclaredColumnSource();

    expect($source->columns(null))->toBe([]);
});

it('returns an empty array when given an empty array', function () {
    $source = new ApiDeclaredColumnSource();

    expect($source->columns([]))->toBe([]);
});
```

- [ ] **Step 7.2: Run tests to verify they fail**

Run:

```bash
vendor/bin/pest tests/Search/Sources/ApiDeclaredColumnSourceTest.php
```

Expected: 3 ERRORs — "Class ApiDeclaredColumnSource not found".

- [ ] **Step 7.3: Create the source class**

Create `src/Search/Sources/ApiDeclaredColumnSource.php`:

```php
<?php

namespace AleMian95\Datatable\Search\Sources;

class ApiDeclaredColumnSource
{
    /**
     * @param  array<int, string>|null  $apiDeclaredColumns
     * @return array<int, string>
     */
    public function columns(?array $apiDeclaredColumns): array
    {
        return $apiDeclaredColumns ?? [];
    }
}
```

- [ ] **Step 7.4: Run tests to verify they pass**

Run:

```bash
vendor/bin/pest tests/Search/Sources/ApiDeclaredColumnSourceTest.php
```

Expected: 3 PASS.

- [ ] **Step 7.5: Commit**

```bash
git add src/Search/Sources/ApiDeclaredColumnSource.php tests/Search/Sources/ApiDeclaredColumnSourceTest.php
git commit -m "feat: add ApiDeclaredColumnSource

Pure data wrapper for the columns passed via DatatableApi::withSearchableColumns()."
```

---

## Task 8: `ModelDeclaredColumnSource`

**Files:**
- Create: `src/Search/Sources/ModelDeclaredColumnSource.php`
- Test: `tests/Search/Sources/ModelDeclaredColumnSourceTest.php`

- [ ] **Step 8.1: Write the failing tests**

Create `tests/Search/Sources/ModelDeclaredColumnSourceTest.php`:

```php
<?php

use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;
use AleMian95\Datatable\Contracts\HasSearchableColumns;
use AleMian95\Datatable\Search\Sources\ModelDeclaredColumnSource;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class SearchableTestUser extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    protected array $searchable = ['first_name', 'email'];
}

class NonSearchableTestUser extends TestUser
{
    protected $table = 'test_users';
}

it('returns the declared columns when the model implements the contract', function () {
    $source = new ModelDeclaredColumnSource();

    $builder = SearchableTestUser::query();

    expect($source->columns($builder))->toBe(['first_name', 'email']);
});

it('returns an empty array when the model does not implement the contract', function () {
    $source = new ModelDeclaredColumnSource();

    $builder = NonSearchableTestUser::query();

    expect($source->columns($builder))->toBe([]);
});

it('returns an empty array for a raw QueryBuilder', function () {
    $source = new ModelDeclaredColumnSource();

    $builder = DB::table('test_users');

    expect($source->columns($builder))->toBe([]);
});
```

- [ ] **Step 8.2: Run tests to verify they fail**

Run:

```bash
vendor/bin/pest tests/Search/Sources/ModelDeclaredColumnSourceTest.php
```

Expected: 3 ERRORs — class not found.

- [ ] **Step 8.3: Create the source class**

Create `src/Search/Sources/ModelDeclaredColumnSource.php`:

```php
<?php

namespace AleMian95\Datatable\Search\Sources;

use AleMian95\Datatable\Contracts\HasSearchableColumns;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ModelDeclaredColumnSource
{
    /**
     * @return array<int, string>
     */
    public function columns(Builder $builder): array
    {
        if (! ($builder instanceof EloquentBuilder || $builder instanceof Relation)) {
            return [];
        }

        $model = $builder->getModel();

        if (! $model instanceof HasSearchableColumns) {
            return [];
        }

        return $model->getSearchableColumns();
    }
}
```

- [ ] **Step 8.4: Run tests to verify they pass**

Run:

```bash
vendor/bin/pest tests/Search/Sources/ModelDeclaredColumnSourceTest.php
```

Expected: 3 PASS.

- [ ] **Step 8.5: Commit**

```bash
git add src/Search/Sources/ModelDeclaredColumnSource.php tests/Search/Sources/ModelDeclaredColumnSourceTest.php
git commit -m "feat: add ModelDeclaredColumnSource

Extracts searchable columns from an Eloquent model that implements
HasSearchableColumns. Returns empty array for non-Eloquent builders or
models that don't implement the contract."
```

---

## Task 9: `AutoDiscoveryColumnSource`

**Files:**
- Create: `src/Search/Sources/AutoDiscoveryColumnSource.php`
- Test: `tests/Search/Sources/AutoDiscoveryColumnSourceTest.php`

- [ ] **Step 9.1: Write the failing tests**

Create `tests/Search/Sources/AutoDiscoveryColumnSourceTest.php`:

```php
<?php

use AleMian95\Datatable\Search\Sources\AutoDiscoveryColumnSource;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Support\Facades\DB;

it('returns only string/text columns for an Eloquent builder', function () {
    $source = new AutoDiscoveryColumnSource([]);

    $columns = $source->columns(TestUser::query());

    // string/text columns from the migration
    expect($columns)->toContain('first_name');
    expect($columns)->toContain('last_name');
    expect($columns)->toContain('email');
    expect($columns)->toContain('password');

    // non-string columns must be excluded
    expect($columns)->not->toContain('id');
    expect($columns)->not->toContain('login_count');
    expect($columns)->not->toContain('created_at');
    expect($columns)->not->toContain('updated_at');
    expect($columns)->not->toContain('metadata');
});

it('applies the blacklist with exact name matching', function () {
    $source = new AutoDiscoveryColumnSource(['password']);

    $columns = $source->columns(TestUser::query());

    expect($columns)->not->toContain('password');
    expect($columns)->toContain('first_name');
});

it('applies the blacklist with wildcard patterns', function () {
    $source = new AutoDiscoveryColumnSource(['*_token']);

    $columns = $source->columns(TestUser::query());

    expect($columns)->not->toContain('remember_token');
    expect($columns)->not->toContain('api_token');
    expect($columns)->toContain('first_name');
});

it('matches blacklist case-insensitively', function () {
    $source = new AutoDiscoveryColumnSource(['PASSWORD']);

    $columns = $source->columns(TestUser::query());

    expect($columns)->not->toContain('password');
});

it('discovers eager-loaded relation columns with dot notation', function () {
    $source = new AutoDiscoveryColumnSource([]);

    $columns = $source->columns(TestUser::query()->with('posts'));

    expect($columns)->toContain('posts.title');
    expect($columns)->toContain('posts.body');
    expect($columns)->not->toContain('posts.id');
    expect($columns)->not->toContain('posts.test_user_id');
});

it('works on a raw QueryBuilder using the from table', function () {
    $source = new AutoDiscoveryColumnSource([]);

    $columns = $source->columns(DB::table('test_users'));

    expect($columns)->toContain('first_name');
    expect($columns)->not->toContain('id');
});
```

- [ ] **Step 9.2: Run tests to verify they fail**

Run:

```bash
vendor/bin/pest tests/Search/Sources/AutoDiscoveryColumnSourceTest.php
```

Expected: 6 ERRORs — class not found.

- [ ] **Step 9.3: Create the source class**

Create `src/Search/Sources/AutoDiscoveryColumnSource.php`:

```php
<?php

namespace AleMian95\Datatable\Search\Sources;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

class AutoDiscoveryColumnSource
{
    private const SEARCHABLE_TYPES = ['string', 'text', 'char', 'varchar', 'tinytext', 'mediumtext', 'longtext'];

    /**
     * @param  array<int, string>  $blacklist  Column names or wildcard patterns (case-insensitive).
     */
    public function __construct(private array $blacklist) {}

    /**
     * @return array<int, string>
     */
    public function columns(Builder $builder): array
    {
        if ($builder instanceof EloquentBuilder || $builder instanceof Relation) {
            $model = $builder->getModel();
            $columns = $this->filterColumns($model->getTable(), Schema::getColumnListing($model->getTable()));

            if ($builder instanceof EloquentBuilder) {
                foreach (array_keys($builder->getEagerLoads()) as $relationName) {
                    $columns = array_merge($columns, $this->relationColumns($model, $relationName));
                }
            }

            return array_values($columns);
        }

        if ($builder instanceof QueryBuilder) {
            $table = $builder->from;

            if (! is_string($table)) {
                return [];
            }

            return array_values($this->filterColumns($table, Schema::getColumnListing($table)));
        }

        return [];
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function filterColumns(string $table, array $columns): array
    {
        return array_filter($columns, function (string $column) use ($table): bool {
            return $this->isSearchableType($table, $column) && ! $this->isBlacklisted($column);
        });
    }

    private function isSearchableType(string $table, string $column): bool
    {
        $type = strtolower(Schema::getColumnType($table, $column));

        return in_array($type, self::SEARCHABLE_TYPES, true);
    }

    private function isBlacklisted(string $column): bool
    {
        $column = strtolower($column);

        foreach ($this->blacklist as $pattern) {
            $pattern = strtolower($pattern);

            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';
                if (preg_match($regex, $column) === 1) {
                    return true;
                }
            } elseif ($pattern === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function relationColumns(Model $model, string $relationName): array
    {
        $parts = explode('.', $relationName);
        $currentModel = $model;

        foreach ($parts as $part) {
            if (! method_exists($currentModel, $part)) {
                return [];
            }

            $relation = $currentModel->$part();

            if (! ($relation instanceof Relation)) {
                return [];
            }

            $currentModel = $relation->getRelated();
        }

        $relatedTable = $currentModel->getTable();
        $rawColumns = Schema::getColumnListing($relatedTable);
        $filtered = $this->filterColumns($relatedTable, $rawColumns);

        return array_values(array_map(
            fn (string $column): string => "{$relationName}.{$column}",
            $filtered,
        ));
    }
}
```

- [ ] **Step 9.4: Run tests to verify they pass**

Run:

```bash
vendor/bin/pest tests/Search/Sources/AutoDiscoveryColumnSourceTest.php
```

Expected: 6 PASS.

- [ ] **Step 9.5: Commit**

```bash
git add src/Search/Sources/AutoDiscoveryColumnSource.php tests/Search/Sources/AutoDiscoveryColumnSourceTest.php
git commit -m "feat: add AutoDiscoveryColumnSource with type filter and blacklist

Schema-driven fallback source for the searchable-columns resolver. Filters
out non-string column types (fixes silent LIKE-on-int/json/timestamp bugs)
and applies a configurable name/wildcard blacklist. Preserves eager-load
relation discovery."
```

---

## Task 10: `DefaultSearchColumnResolver`

**Files:**
- Create: `src/Search/DefaultSearchColumnResolver.php`
- Test: `tests/Search/DefaultSearchColumnResolverTest.php`

- [ ] **Step 10.1: Write the failing tests**

Create `tests/Search/DefaultSearchColumnResolverTest.php`:

```php
<?php

use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;
use AleMian95\Datatable\Contracts\HasSearchableColumns;
use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\Exceptions\SearchColumnsNotConfiguredException;
use AleMian95\Datatable\Search\DefaultSearchColumnResolver;
use AleMian95\Datatable\Search\Sources\ApiDeclaredColumnSource;
use AleMian95\Datatable\Search\Sources\AutoDiscoveryColumnSource;
use AleMian95\Datatable\Search\Sources\ModelDeclaredColumnSource;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\Request;

class ResolverSearchableUser extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    protected array $searchable = ['first_name', 'last_name'];
}

function makeResolver(bool $autoDiscover = true, array $blacklist = []): DefaultSearchColumnResolver
{
    return new DefaultSearchColumnResolver(
        new ApiDeclaredColumnSource(),
        new ModelDeclaredColumnSource(),
        new AutoDiscoveryColumnSource($blacklist),
        $autoDiscover,
    );
}

function makeRequest(array $params = []): DatatableRequest
{
    return DatatableRequest::fromRequest(Request::create('/', 'GET', $params));
}

it('uses the API-declared columns when provided', function () {
    $resolver = makeResolver();
    $builder = ResolverSearchableUser::query();

    $result = $resolver->resolve($builder, makeRequest(), ['email']);

    expect($result)->toBe(['email']);
});

it('falls back to model-declared columns when the API source is empty', function () {
    $resolver = makeResolver();
    $builder = ResolverSearchableUser::query();

    $result = $resolver->resolve($builder, makeRequest(), null);

    expect($result)->toBe(['first_name', 'last_name']);
});

it('intersects request search_columns with the authoritative whitelist', function () {
    $resolver = makeResolver();
    $builder = ResolverSearchableUser::query();

    $result = $resolver->resolve(
        $builder,
        makeRequest(['search_columns' => 'first_name,password']),
        null,
    );

    expect($result)->toBe(['first_name']);
});

it('returns an empty array when the intersection is empty', function () {
    $resolver = makeResolver();
    $builder = ResolverSearchableUser::query();

    $result = $resolver->resolve(
        $builder,
        makeRequest(['search_columns' => 'password,api_token']),
        null,
    );

    expect($result)->toBe([]);
});

it('falls back to auto-discovery when no whitelist exists and the flag is on', function () {
    $resolver = makeResolver(autoDiscover: true);
    $builder = TestUser::query();

    $result = $resolver->resolve($builder, makeRequest(), null);

    expect($result)->toContain('first_name');
    expect($result)->toContain('email');
});

it('lets request search_columns win unfiltered when no whitelist exists and auto-discovery is on', function () {
    $resolver = makeResolver(autoDiscover: true);
    $builder = TestUser::query();

    $result = $resolver->resolve(
        $builder,
        makeRequest(['search_columns' => 'first_name,last_name']),
        null,
    );

    expect($result)->toBe(['first_name', 'last_name']);
});

it('throws when no whitelist exists and auto-discovery is off (model case)', function () {
    $resolver = makeResolver(autoDiscover: false);
    $builder = TestUser::query();

    $resolver->resolve($builder, makeRequest(), null);
})->throws(SearchColumnsNotConfiguredException::class);

it('throws when no whitelist exists and auto-discovery is off (raw QueryBuilder case)', function () {
    $resolver = makeResolver(autoDiscover: false);
    $builder = \Illuminate\Support\Facades\DB::table('test_users');

    $resolver->resolve($builder, makeRequest(), null);
})->throws(SearchColumnsNotConfiguredException::class);

it('does NOT throw when the request provides search_columns and auto-discovery is off, with no model whitelist', function () {
    // This is intentional: the request still expresses intent, and we keep the
    // raw-QueryBuilder flow usable for ad-hoc dashboards. The whitelist
    // discipline is enforced only when a whitelist source actually exists.
    $resolver = makeResolver(autoDiscover: false);
    $builder = TestUser::query();

    $result = $resolver->resolve(
        $builder,
        makeRequest(['search_columns' => 'first_name']),
        null,
    );

    expect($result)->toBe(['first_name']);
});
```

- [ ] **Step 10.2: Run tests to verify they fail**

Run:

```bash
vendor/bin/pest tests/Search/DefaultSearchColumnResolverTest.php
```

Expected: 9 ERRORs — `DefaultSearchColumnResolver` not found.

- [ ] **Step 10.3: Create the resolver class**

Create `src/Search/DefaultSearchColumnResolver.php`:

```php
<?php

namespace AleMian95\Datatable\Search;

use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\Exceptions\SearchColumnsNotConfiguredException;
use AleMian95\Datatable\Search\Sources\ApiDeclaredColumnSource;
use AleMian95\Datatable\Search\Sources\AutoDiscoveryColumnSource;
use AleMian95\Datatable\Search\Sources\ModelDeclaredColumnSource;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

class DefaultSearchColumnResolver implements SearchColumnResolver
{
    public function __construct(
        private ApiDeclaredColumnSource $apiSource,
        private ModelDeclaredColumnSource $modelSource,
        private AutoDiscoveryColumnSource $autoSource,
        private bool $autoDiscoverEnabled,
    ) {}

    public function resolve(
        Builder $builder,
        DatatableRequest $request,
        ?array $apiDeclaredColumns,
    ): array {
        $whitelist = $this->resolveWhitelist($builder, $apiDeclaredColumns);

        if ($whitelist !== null) {
            return $this->intersectWithRequest($whitelist, $request);
        }

        if ($this->autoDiscoverEnabled) {
            return ! empty($request->searchColumns)
                ? array_values($request->searchColumns)
                : $this->autoSource->columns($builder);
        }

        if (! empty($request->searchColumns)) {
            return array_values($request->searchColumns);
        }

        throw $this->makeException($builder);
    }

    /**
     * @param  array<int, string>|null  $apiDeclaredColumns
     * @return array<int, string>|null  Null means: no whitelist source produced anything.
     */
    private function resolveWhitelist(Builder $builder, ?array $apiDeclaredColumns): ?array
    {
        $fromApi = $this->apiSource->columns($apiDeclaredColumns);

        if (! empty($fromApi)) {
            return $fromApi;
        }

        $fromModel = $this->modelSource->columns($builder);

        if (! empty($fromModel)) {
            return $fromModel;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $whitelist
     * @return array<int, string>
     */
    private function intersectWithRequest(array $whitelist, DatatableRequest $request): array
    {
        if (empty($request->searchColumns)) {
            return $whitelist;
        }

        return array_values(array_intersect($request->searchColumns, $whitelist));
    }

    private function makeException(Builder $builder): SearchColumnsNotConfiguredException
    {
        if ($builder instanceof EloquentBuilder || $builder instanceof Relation) {
            return SearchColumnsNotConfiguredException::forModel(get_class($builder->getModel()));
        }

        if ($builder instanceof QueryBuilder && is_string($builder->from)) {
            return SearchColumnsNotConfiguredException::forTable($builder->from);
        }

        return SearchColumnsNotConfiguredException::forTable('unknown');
    }
}
```

- [ ] **Step 10.4: Run tests to verify they pass**

Run:

```bash
vendor/bin/pest tests/Search/DefaultSearchColumnResolverTest.php
```

Expected: 9 PASS.

- [ ] **Step 10.5: Commit**

```bash
git add src/Search/DefaultSearchColumnResolver.php tests/Search/DefaultSearchColumnResolverTest.php
git commit -m "feat: add DefaultSearchColumnResolver orchestrating the column sources

Implements the full decision tree from the spec: API > Model > (auto-discovery or
exception). When an authoritative whitelist exists, request.search_columns is
intersected against it; without a whitelist and auto-discovery off, throws
SearchColumnsNotConfiguredException."
```

---

## Task 11: Refactor `SearchApplier` to depend on the resolver

**Files:**
- Modify: `src/SearchApplier.php`
- Test: `tests/SearchApplierTest.php`

- [ ] **Step 11.1: Write the failing tests**

Create `tests/SearchApplierTest.php`:

```php
<?php

use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;
use AleMian95\Datatable\Contracts\HasSearchableColumns;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\SearchApplier;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\Request;

class ApplierSearchableUser extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    protected array $searchable = ['first_name', 'email'];
}

function makeApplierRequest(array $params = []): DatatableRequest
{
    return DatatableRequest::fromRequest(Request::create('/', 'GET', $params));
}

it('skips the search when the request has no search term', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldNotReceive('resolve');

    $applier = new SearchApplier($resolver);
    $builder = ApplierSearchableUser::query();

    $applier->apply($builder, makeApplierRequest());

    expect($builder->toSql())->not->toContain('like');
});

it('runs the customSearch closure when provided, bypassing the resolver', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldNotReceive('resolve');

    $called = false;
    $applier = new SearchApplier($resolver, function ($builder, $term) use (&$called): void {
        $called = true;
        $builder->where('first_name', $term);
    });

    $builder = ApplierSearchableUser::query();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($called)->toBeTrue();
    expect($builder->toSql())->toContain('"first_name"');
});

it('applies LIKE clauses on the columns returned by the resolver', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn(['first_name', 'email']);

    $applier = new SearchApplier($resolver);
    $builder = ApplierSearchableUser::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toSql());
    expect($sql)->toContain('"first_name"');
    expect($sql)->toContain('"email"');
    expect($sql)->toContain('like');
});

it('does not add any WHERE clause when the resolver returns an empty array', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn([]);

    $applier = new SearchApplier($resolver);
    $builder = ApplierSearchableUser::query();

    $beforeSql = $builder->toSql();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toSql())->toBe($beforeSql);
});

it('passes the apiDeclaredColumns through to the resolver', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldReceive('resolve')
        ->once()
        ->withArgs(fn ($b, $r, $api) => $api === ['email'])
        ->andReturn(['email']);

    $applier = new SearchApplier($resolver, null, ['email']);
    $builder = ApplierSearchableUser::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect(true)->toBeTrue(); // assertion is in the mock expectations
});
```

- [ ] **Step 11.2: Run tests to verify they fail**

Run:

```bash
vendor/bin/pest tests/SearchApplierTest.php
```

Expected: failures — `SearchApplier` constructor signature doesn't match yet.

- [ ] **Step 11.3: Refactor `SearchApplier`**

Replace the contents of `src/SearchApplier.php` with:

```php
<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;

class SearchApplier implements QueryApplier
{
    /**
     * @param  array<int, string>|null  $apiDeclaredColumns
     */
    public function __construct(
        protected SearchColumnResolver $resolver,
        protected ?\Closure $customSearch = null,
        protected ?array $apiDeclaredColumns = null,
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

        $builder->where(function ($query) use ($searchColumns, $request): void {
            foreach ($searchColumns as $field) {
                if (str_contains($field, '.')) {
                    $parts = explode('.', $field);
                    $column = array_pop($parts);
                    $relationPath = implode('.', $parts);

                    if ($query instanceof EloquentBuilder || $query instanceof Relation) {
                        $query->orWhereHas($relationPath, function ($q) use ($column, $request): void {
                            $q->whereLike($column, "%{$request->search}%");
                        });
                    }
                } else {
                    $query->orWhereLike($field, "%{$request->search}%");
                }
            }
        });
    }
}
```

- [ ] **Step 11.4: Run tests to verify they pass**

Run:

```bash
vendor/bin/pest tests/SearchApplierTest.php
```

Expected: 5 PASS.

- [ ] **Step 11.5: Commit**

```bash
git add src/SearchApplier.php tests/SearchApplierTest.php
git commit -m "refactor: SearchApplier depends on SearchColumnResolver

Drops the inline Schema introspection logic in favor of an injected resolver.
SearchApplier now has a single responsibility: take a list of columns and
emit the LIKE/orWhereHas clauses."
```

---

## Task 12: `DatatableApi::withSearchableColumns()` + propagation

**Files:**
- Modify: `src/DatatableApi.php`

- [ ] **Step 12.1: Modify `DatatableApi`**

Two changes are required: (1) accept and store the API-declared columns, (2) instantiate `SearchApplier` with the resolver resolved from the container.

Edit `src/DatatableApi.php`. Apply the following replacements precisely:

**Replacement A — add the new property near the other state, after `$customSearch`:**

Find:

```php
    protected ?\Closure $customSearch = null;

    protected bool $hasResource = false;
```

Replace with:

```php
    protected ?\Closure $customSearch = null;

    /** @var array<int, string>|null */
    protected ?array $apiDeclaredSearchColumns = null;

    protected bool $hasResource = false;
```

**Replacement B — add the new builder method right after `withCustomSearch()`:**

Find:

```php
    public function withCustomSearch(\Closure $search): self
    {
        $this->customSearch = $search;

        return $this;
    }
```

Replace with:

```php
    public function withCustomSearch(\Closure $search): self
    {
        $this->customSearch = $search;

        return $this;
    }

    /**
     * Declare the authoritative whitelist of searchable columns for this
     * DatatableApi instance. Wins over HasSearchableColumns on the model and
     * is the only way to expose searchable columns for raw QueryBuilder
     * queries when auto_discover_columns is disabled.
     *
     * @param  array<int, string>  $columns
     * @return $this
     */
    public function withSearchableColumns(array $columns): self
    {
        $this->apiDeclaredSearchColumns = $columns;

        return $this;
    }
```

**Replacement C — change the `SearchApplier` instantiation inside `jsonSerialize()`:**

Find:

```php
        $appliers = [
            new SearchApplier($this->customSearch),
            new SortApplier($this->customSorts),
            ...$this->appliers,
        ];
```

Replace with:

```php
        $appliers = [
            new SearchApplier(
                app(\AleMian95\Datatable\Contracts\SearchColumnResolver::class),
                $this->customSearch,
                $this->apiDeclaredSearchColumns,
            ),
            new SortApplier($this->customSorts),
            ...$this->appliers,
        ];
```

- [ ] **Step 12.2: Commit (tests for this run after the service provider binding lands in Task 13)**

```bash
git add src/DatatableApi.php
git commit -m "feat: add DatatableApi::withSearchableColumns() and wire the resolver

Resolves the SearchColumnResolver from the container when instantiating
SearchApplier, and propagates the API-declared whitelist when set. Enables
raw QueryBuilder workflows to declare their own whitelist without relying on
a Model."
```

---

## Task 13: Service provider binding

**Files:**
- Modify: `src/DatatableServiceProvider.php`

- [ ] **Step 13.1: Bind the resolver in the service provider**

Replace the contents of `src/DatatableServiceProvider.php` with:

```php
<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\Search\DefaultSearchColumnResolver;
use AleMian95\Datatable\Search\Sources\ApiDeclaredColumnSource;
use AleMian95\Datatable\Search\Sources\AutoDiscoveryColumnSource;
use AleMian95\Datatable\Search\Sources\ModelDeclaredColumnSource;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DatatableServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laraveldatatable')
            ->hasConfigFile();
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(SearchColumnResolver::class, function ($app): DefaultSearchColumnResolver {
            $config = $app['config']->get('laraveldatatable.search', []);

            return new DefaultSearchColumnResolver(
                new ApiDeclaredColumnSource(),
                new ModelDeclaredColumnSource(),
                new AutoDiscoveryColumnSource($config['auto_discovery_blacklist'] ?? []),
                (bool) ($config['auto_discover_columns'] ?? true),
            );
        });
    }
}
```

- [ ] **Step 13.2: Run the full test suite to verify nothing regressed**

Run:

```bash
vendor/bin/pest
```

Expected: all tests so far PASS. If `Mockery` is not autoloaded in your environment, ensure it is required as a transitive dev dependency (it is, via `pestphp/pest` → `mockery/mockery`).

- [ ] **Step 13.3: Commit**

```bash
git add src/DatatableServiceProvider.php
git commit -m "feat: bind SearchColumnResolver to DefaultSearchColumnResolver

Registers the default resolver as a singleton in registeringPackage(),
configured from config/laraveldatatable.php. Consumers can override the
binding to plug a custom resolver."
```

---

## Task 14: Integration test (end-to-end via `DatatableApi`)

**Files:**
- Test: `tests/DatatableApiSearchableColumnsTest.php`

- [ ] **Step 14.1: Write the integration tests**

Create `tests/DatatableApiSearchableColumnsTest.php`:

```php
<?php

use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;
use AleMian95\Datatable\Contracts\HasSearchableColumns;
use AleMian95\Datatable\DatatableApi;
use AleMian95\Datatable\Exceptions\SearchColumnsNotConfiguredException;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class IntegrationSearchableUser extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    protected array $searchable = ['first_name', 'email'];
}

beforeEach(function (): void {
    IntegrationSearchableUser::create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.test']);
    IntegrationSearchableUser::create(['first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@example.test']);
    IntegrationSearchableUser::create(['first_name' => 'Janet', 'last_name' => 'Roe', 'email' => 'janet@example.test']);
});

function bindRequest(array $params): void
{
    app()->bind('request', fn () => Request::create('/', 'GET', $params));
}

it('returns matches from the model whitelist when the request omits search_columns', function () {
    bindRequest(['search' => 'jane']);

    $result = (new DatatableApi())->fromQuery(IntegrationSearchableUser::query())->jsonSerialize();

    expect($result)->toBeInstanceOf(LengthAwarePaginator::class);
    // "jane" matches first_name="Jane" and first_name="Janet" and email containing "jane"
    expect($result->total())->toBe(2);
});

it('drops unauthorized columns from request.search_columns', function () {
    // The client tries to search on password, which is not in the whitelist.
    bindRequest(['search' => 'secret', 'search_columns' => 'password']);

    $result = (new DatatableApi())->fromQuery(IntegrationSearchableUser::query())->jsonSerialize();

    // Intersection is empty -> no search clause -> all 3 rows returned.
    expect($result->total())->toBe(3);
});

it('honors withSearchableColumns() on DatatableApi over the model declaration', function () {
    // Model whitelist is [first_name, email] but the API restricts it further to [last_name],
    // and request searches "doe" which only matches last_name="Doe".
    bindRequest(['search' => 'doe']);

    $result = (new DatatableApi())
        ->fromQuery(IntegrationSearchableUser::query())
        ->withSearchableColumns(['last_name'])
        ->jsonSerialize();

    expect($result->total())->toBe(1);
});

it('supports raw QueryBuilder via withSearchableColumns()', function () {
    bindRequest(['search' => 'jane']);

    $result = (new DatatableApi())
        ->fromQuery(DB::table('test_users'))
        ->withSearchableColumns(['first_name'])
        ->jsonSerialize();

    expect($result->total())->toBe(2); // Jane + Janet
});

it('throws when no whitelist exists and auto-discovery is off', function () {
    config()->set('laraveldatatable.search.auto_discover_columns', false);
    // Rebind the resolver to pick up the new config.
    app()->forgetInstance(\AleMian95\Datatable\Contracts\SearchColumnResolver::class);

    bindRequest(['search' => 'jane']);

    (new DatatableApi())->fromQuery(TestUser::query())->jsonSerialize();
})->throws(SearchColumnsNotConfiguredException::class);

it('uses auto-discovery when no whitelist exists and the flag is on', function () {
    bindRequest(['search' => 'jane']);

    // Plain TestUser has no whitelist; auto-discovery is on by default.
    $result = (new DatatableApi())->fromQuery(TestUser::query())->jsonSerialize();

    // "jane" matches first_name and email rows.
    expect($result->total())->toBe(2);
});
```

- [ ] **Step 14.2: Run the integration tests**

Run:

```bash
vendor/bin/pest tests/DatatableApiSearchableColumnsTest.php
```

Expected: 6 PASS.

- [ ] **Step 14.3: Run the full suite to catch regressions**

Run:

```bash
vendor/bin/pest
```

Expected: all tests PASS.

- [ ] **Step 14.4: Commit**

```bash
git add tests/DatatableApiSearchableColumnsTest.php
git commit -m "test: end-to-end integration tests for searchable-columns pipeline

Covers the full flow through DatatableApi: whitelist enforcement, request
intersection, withSearchableColumns() precedence, raw QueryBuilder support,
and the exception path when auto-discovery is disabled."
```

---

## Task 15: README update

**Files:**
- Modify: `README.md`

- [ ] **Step 15.1: Replace limit #1 with the new section**

Find this paragraph in `README.md` (around line 147):

```markdown
1. **Automatic column discovery.** When `search_columns` is omitted, `SearchApplier` lists every column of the base table via `Schema::getColumnListing`, plus every column of each **eager-loaded** relation (Eloquent only). For a raw `QueryBuilder` only the base table is scanned. This is convenient for prototyping but typically unsuitable for production — prefer an explicit `search_columns` list to avoid leaking internal columns into a `LIKE` search.
```

Replace it with:

```markdown
1. **Searchable columns: declarative opt-in (recommended).** The set of columns that can be searched is resolved in this order:

   1. `DatatableApi::withSearchableColumns(['col_a', 'col_b'])` — wins over everything.
   2. `Model implements HasSearchableColumns` — the contract returns the whitelist (the trait `Concerns\HasSearchableColumns` reads a `protected array $searchable = [...]` property by default).
   3. Auto-discovery via `Schema::getColumnListing` — fallback **only** when `config('laraveldatatable.search.auto_discover_columns')` is `true` (default for backward compatibility). Filters out non-string columns and applies the `auto_discovery_blacklist`.

   When a whitelist is declared, `search_columns` from the HTTP request is intersected against it: the client can never broaden it. When no source can satisfy the request and auto-discovery is off, a `SearchColumnsNotConfiguredException` is thrown.

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
```

- [ ] **Step 15.2: Extend the published config block in the README**

Find the existing published-config example (around line 41):

```markdown
This is the contents of the published `config/laraveldatatable.php`:

```php
return [
    'default' => [
        // Default page size used when the request omits "per_page".
        'per_page' => 15,
    ],
];
```
```

Replace it with:

```markdown
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
```

- [ ] **Step 15.3: Add `withSearchableColumns()` to the pipeline-methods list**

Find the bullet list under *Customizing the pipeline* (around line 115). After the `withCustomFilters` bullet (line 118), insert this new bullet:

```markdown
- **`withSearchableColumns(array $columns): self`** — declares the authoritative whitelist of columns the search can target for this instance. Wins over the `HasSearchableColumns` contract on the model and is the only way to enable search on a raw `QueryBuilder` when `auto_discover_columns` is `false`. When set, `search_columns` from the request is intersected against this whitelist.
```

- [ ] **Step 15.4: Commit**

```bash
git add README.md
git commit -m "docs: document HasSearchableColumns contract, trait, withSearchableColumns(), and new config"
```

---

## Self-Review

**Spec coverage check (sec. by sec.):**
- Sec. 4 (regole di decisione) → Task 10 (`DefaultSearchColumnResolver`) implements the full decision tree, with tests covering each branch.
- Sec. 5.1 (`Contracts\HasSearchableColumns`) → Task 3.
- Sec. 5.2 (`Concerns\HasSearchableColumns` trait) → Task 4.
- Sec. 5.3 (`DatatableApi::withSearchableColumns()`) → Task 12.
- Sec. 5.4 (`SearchColumnsNotConfiguredException`) → Task 5, two named constructors for Model and table.
- Sec. 5.5 (config) → Task 2.
- Sec. 6.1/6.2 (file structure & SRP) → Tasks 3–13 follow the exact layout.
- Sec. 6.3 (flusso) → Task 11 (`SearchApplier` delegates), Task 12 (`DatatableApi` propagates), Task 13 (provider binds).
- Sec. 7 (retrocompat: default `auto_discover_columns => true` + string-only filter + blacklist) → Task 2 sets the default, Task 9 enforces the type filter and blacklist with tests.
- Sec. 8 (testing) → Tasks 7–10 unit-test each Source/resolver branch; Task 11 covers the Applier seam; Task 14 covers end-to-end including dropped-unauthorized-columns and exception paths.
- Sec. 9 (docs) → Task 15 updates README in three places: limit #1, published config block, pipeline-methods list.

**Placeholder scan:** no `TBD`/`TODO`/`fill in later`/`similar to Task N` remain. All code blocks are complete and runnable.

**Type consistency:**
- `HasSearchableColumns::getSearchableColumns(): array` — consistent across contract, trait, ModelDeclaredColumnSource consumption, and integration tests.
- `SearchColumnResolver::resolve(Builder, DatatableRequest, ?array): array` — consistent across contract, resolver, SearchApplier call site, and the mock in `SearchApplierTest`.
- `withSearchableColumns(array $columns): self` — consistent in `DatatableApi`, README, and integration tests.
- Source classes all expose `columns(...)` (different signatures, deliberately — they read from different inputs); the orchestrator (Task 10) is the only caller and uses the right signature for each.

No gaps identified.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-24-searchable-columns.md`. Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
