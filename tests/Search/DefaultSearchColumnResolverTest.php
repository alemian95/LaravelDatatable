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
