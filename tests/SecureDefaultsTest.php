<?php

use AleMian95\Datatable\DatatableApi;
use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\Exceptions\SearchColumnsNotConfiguredException;
use AleMian95\Datatable\SortApplier;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

function defaultsRequest(array $params): Request
{
    return Request::create('/', 'GET', $params);
}

it('ships with auto-discovery off', function () {
    expect(config('laraveldatatable.search.auto_discover_columns'))->toBeFalse();
});

it('throws on a search when no searchable columns are declared', function () {
    DatatableApi::for(TestUser::query(), defaultsRequest(['search' => 'jane']))->toPaginator();
})->throws(SearchColumnsNotConfiguredException::class);

it('ignores sort_by with a warning when no sortable columns are declared', function () {
    Log::spy();
    $builder = TestUser::query();

    (new SortApplier)->apply($builder, DatatableRequest::fromRequest(defaultsRequest(['sort_by' => 'password'])));

    expect($builder->toSql())->not->toContain('order by');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '[password]'))->once();
});

it('still applies a custom sort when no whitelist is declared', function () {
    $builder = TestUser::query();

    (new SortApplier(['full' => fn ($q, string $dir) => $q->orderBy('last_name', $dir)]))
        ->apply($builder, DatatableRequest::fromRequest(defaultsRequest(['sort_by' => 'full'])));

    expect($builder->toSql())->toContain('order by "last_name" asc');
});

it('warns about requested search columns outside the whitelist', function () {
    Log::spy();

    DatatableApi::for(TestUser::query(), defaultsRequest(['search' => 'x', 'search_columns' => 'first_name,password']))
        ->withSearchableColumns(['first_name'])
        ->toPaginator();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '[password]'))->once();
});
