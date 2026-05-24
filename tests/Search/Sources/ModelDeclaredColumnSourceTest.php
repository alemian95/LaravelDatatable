<?php

use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;
use AleMian95\Datatable\Contracts\HasSearchableColumns;
use AleMian95\Datatable\Search\Sources\ModelDeclaredColumnSource;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
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

class EmptyWhitelistTestUser extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    protected array $searchable = [];
}

it('returns the declared columns when the model implements the contract', function () {
    $source = new ModelDeclaredColumnSource();

    $builder = SearchableTestUser::query();

    expect($source->columns($builder))->toBe(['first_name', 'email']);
});

it('returns null when the model does not implement the contract', function () {
    $source = new ModelDeclaredColumnSource();

    $builder = NonSearchableTestUser::query();

    expect($source->columns($builder))->toBeNull();
});

it('returns null for a raw QueryBuilder', function () {
    $source = new ModelDeclaredColumnSource();

    $builder = DB::table('test_users');

    expect($source->columns($builder))->toBeNull();
});

it('preserves an empty whitelist as an authoritative block', function () {
    $source = new ModelDeclaredColumnSource();

    $builder = EmptyWhitelistTestUser::query();

    expect($source->columns($builder))->toBe([]);
});
