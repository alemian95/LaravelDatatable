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

it('survives eager-load keys with an " as alias" suffix without crashing', function () {
    // Stock Laravel does not emit such keys via ->with(), but unusual constraint
    // strings or future versions could. We inject the key directly to verify
    // the defensive resolution path: method_exists is checked against the part
    // before " as ", while the original key remains the column-prefix.
    $source = new AutoDiscoveryColumnSource([]);

    $builder = TestUser::query();
    $builder->setEagerLoads(['posts as p' => fn ($q) => $q]);

    $columns = $source->columns($builder);

    // Best-effort: the dot-notation prefix is the original key (no opinion on
    // what "alias" should mean — we just don't crash and we surface the
    // discovered string columns).
    expect($columns)->toContain('posts as p.title');
    expect($columns)->toContain('posts as p.body');
});
