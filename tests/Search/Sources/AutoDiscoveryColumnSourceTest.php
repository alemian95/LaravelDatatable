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

    // non-string columns must be excluded.
    // NOTE: a json column would also be excluded under MySQL/PostgreSQL
    // (getColumnType returns 'json'), but SQLite stores JSON as TEXT and
    // cannot be distinguished by schema introspection — so we don't assert
    // on the 'metadata' column here.
    expect($columns)->not->toContain('id');
    expect($columns)->not->toContain('login_count');
    expect($columns)->not->toContain('created_at');
    expect($columns)->not->toContain('updated_at');
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

it('strips " as alias" suffixes from eager-load keys both for resolution and prefix', function () {
    // Stock Laravel does not emit such keys via ->with(), but unusual constraint
    // strings or future versions could. We inject the key directly to verify
    // the defensive path: method_exists is checked against the segment before
    // " as ", AND the emitted dot-notation prefix uses the same clean segment
    // so SearchApplier can pass it to orWhereHas() unmodified.
    $source = new AutoDiscoveryColumnSource([]);

    $builder = TestUser::query();
    $builder->setEagerLoads(['posts as p' => fn ($q) => $q]);

    $columns = $source->columns($builder);

    expect($columns)->toContain('posts.title');
    expect($columns)->toContain('posts.body');
    expect($columns)->not->toContain('posts as p.title');
});

it('introspects each table once and only once per source instance', function () {
    $source = new AutoDiscoveryColumnSource([]);
    DB::enableQueryLog();

    $source->columns(TestUser::query());
    $first = count(DB::getQueryLog());
    $source->columns(DB::table('test_users'));

    // A constant number of schema queries for the whole table (SQLite needs
    // two), not one per column as before; none the second time.
    expect($first)->toBeLessThanOrEqual(2)
        ->and(count(DB::getQueryLog()))->toBe($first);
});

it('reads the schema of the connection the builder runs on, cached per connection', function () {
    config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::connection('tenant')->getSchemaBuilder()->create('test_users', function ($table) {
        $table->id();
        $table->string('nickname');
    });

    $source = new AutoDiscoveryColumnSource([]);

    // Default connection first, so a table-only cache key would leak its columns.
    expect($source->columns(DB::table('test_users')))->toContain('first_name')
        ->and($source->columns(DB::connection('tenant')->table('test_users')))->toBe(['nickname']);
});
