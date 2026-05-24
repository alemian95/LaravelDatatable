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

it('omits the search clause end-to-end when withSearchableColumns is called with an empty array', function () {
    bindRequest(['search' => 'jane']);

    $result = (new DatatableApi())
        ->fromQuery(IntegrationSearchableUser::query())
        ->withSearchableColumns([])
        ->jsonSerialize();

    // Empty whitelist is an authoritative "omit the search clause" signal:
    // no LIKE is applied, the dataset is returned unfiltered by the search
    // term, so all 3 rows surface. This distinguishes the new semantics from
    // "no whitelist at all", which would fall back to the model's
    // [first_name, email] declaration and return only the 2 matching rows.
    expect($result->total())->toBe(3);
});

it('drops blacklisted request.search_columns end-to-end via the auto-discovery blacklist', function () {
    // TestUser has NO HasSearchableColumns trait, so no model whitelist.
    // Default config: auto-discover on, blacklist excludes password/*_token/etc.
    // Client tries to search 'secret' on password and api_token. Both are filtered
    // out by the auto-discovery blacklist, so the intersection is empty, no WHERE
    // clause is added, and all rows are returned.
    bindRequest(['search' => 'secret', 'search_columns' => 'password,api_token']);

    $result = (new DatatableApi())->fromQuery(TestUser::query())->jsonSerialize();

    expect($result->total())->toBe(3);
});
