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

it('uses the API-declared columns and ignores the Model whitelist when both exist', function () {
    $resolver = makeResolver();
    $builder = ResolverSearchableUser::query(); // model declares ['first_name', 'last_name']

    $result = $resolver->resolve($builder, makeRequest(), ['email']);

    expect($result)->toBe(['email']);
    expect($result)->not->toContain('first_name');
    expect($result)->not->toContain('last_name');
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

it('intersects request search_columns with the auto-discovery result when no whitelist exists', function () {
    // Both first_name and last_name are valid string columns and are not in the
    // blacklist, so they survive the intersection unchanged.
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

it('throws when no whitelist exists and auto-discovery is off, even if the request provides search_columns', function () {
    // The spec is unconditional: without a declared whitelist (API or Model)
    // and with auto-discovery off, the only way forward is to declare
    // searchable columns. The request alone is not enough — otherwise the
    // strict mode would be trivially bypassable.
    $resolver = makeResolver(autoDiscover: false);
    $builder = TestUser::query();

    $resolver->resolve(
        $builder,
        makeRequest(['search_columns' => 'first_name']),
        null,
    );
})->throws(SearchColumnsNotConfiguredException::class);

it('blocks the search when the API source returns an authoritative empty whitelist', function () {
    $resolver = makeResolver();
    $builder = TestUser::query();

    $result = $resolver->resolve($builder, makeRequest(['search_columns' => 'first_name']), []);

    // Authoritative empty whitelist: intersection with any request columns is empty.
    expect($result)->toBe([]);
});

it('blocks the search when the Model source returns an authoritative empty whitelist', function () {
    // A model that implements HasSearchableColumns but returns [] is asking
    // explicitly for the search to be blocked, not to fall back to auto-discovery.
    $emptyModel = new class extends \AleMian95\Datatable\Tests\Fixtures\Models\TestUser implements \AleMian95\Datatable\Contracts\HasSearchableColumns {
        use \AleMian95\Datatable\Concerns\HasSearchableColumns;

        protected $table = 'test_users';

        protected array $searchable = [];
    };

    $resolver = makeResolver();
    $builder = $emptyModel::query();

    $result = $resolver->resolve($builder, makeRequest(['search_columns' => 'first_name']), null);

    expect($result)->toBe([]);
});

it('drops blacklisted request columns through auto-discovery when no whitelist exists', function () {
    $resolver = makeResolver(autoDiscover: true, blacklist: ['password', '*_token']);
    $builder = TestUser::query();

    $result = $resolver->resolve(
        $builder,
        makeRequest(['search_columns' => 'first_name,password,api_token']),
        null,
    );

    // password and api_token are excluded by the blacklist; first_name survives.
    expect($result)->toBe(['first_name']);
});

it('drops non-string request columns through auto-discovery when no whitelist exists', function () {
    $resolver = makeResolver(autoDiscover: true);
    $builder = TestUser::query();

    $result = $resolver->resolve(
        $builder,
        makeRequest(['search_columns' => 'first_name,id,login_count,created_at']),
        null,
    );

    // id (bigint), login_count (bigint), created_at (timestamp) all fall outside
    // SEARCHABLE_TYPES, so they're filtered out by AutoDiscoveryColumnSource.
    expect($result)->toBe(['first_name']);
});
