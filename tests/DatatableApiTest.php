<?php

use AleMian95\Datatable\DatatableApi;
use AleMian95\Datatable\Tests\Fixtures\Models\TestPost;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $jane = TestUser::create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@test']);
    TestUser::create(['first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@test']);
    TestPost::create(['test_user_id' => $jane->id, 'title' => 'jane post', 'body' => 'b']);
    TestPost::create(['test_user_id' => $jane->id, 'title' => 'other', 'body' => 'b']);
});

function datatableRequest(array $params): Request
{
    return Request::create('/', 'GET', $params);
}

it('uses the explicit request instead of the global one', function () {
    app()->instance('request', datatableRequest(['search' => 'zzz']));

    $result = DatatableApi::for(TestUser::query(), datatableRequest(['search' => 'jane']))
        ->withSearchableColumns(['first_name'])
        ->toPaginator();

    expect($result->total())->toBe(1);
});

it('falls back to the current request when none is passed', function () {
    app()->instance('request', datatableRequest(['search' => 'john']));

    $result = DatatableApi::for(TestUser::query())->withSearchableColumns(['first_name'])->toPaginator();

    expect($result->total())->toBe(1);
});

it('paginates with the page of the explicit request', function () {
    $result = DatatableApi::for(TestUser::query(), datatableRequest(['per_page' => 1, 'page' => 2]))->toPaginator();

    expect($result->currentPage())->toBe(2)
        ->and($result->items()[0]->first_name)->toBe('John');
});

it('can run twice without applying search and sort twice, leaving the query untouched', function ($query) {
    $sqlBefore = sql($query);
    $api = DatatableApi::for($query, datatableRequest(['search' => 'jane', 'sort_by' => 'id']))
        ->withSearchableColumns(['title'])
        ->withSortableColumns(['id']);

    $first = $api->toPaginator()->total();
    $second = $api->toPaginator()->total();

    expect($first)->toBe(1)->and($second)->toBe(1)
        ->and(sql($query))->toBe($sqlBefore);
})->with([
    'eloquent' => fn () => TestPost::query(),
    'raw' => fn () => DB::table('test_posts'),
    'relation' => fn () => TestUser::where('first_name', 'Jane')->first()->posts(),
]);

it('is Responsable and returns the paginator as JSON', function () {
    $response = DatatableApi::for(TestUser::query(), datatableRequest([]))->toResponse(datatableRequest([]));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true)['total'])->toBe(2);
});

it('returns the resource envelope with meta when a resource is set', function () {
    $response = DatatableApi::for(TestUser::query(), datatableRequest([]))
        ->returnResource(JsonResource::class)
        ->toResponse(datatableRequest([]));

    expect($response->getData(true)['meta']['total'])->toBe(2);
});

it('serializes a resource result with the same envelope as the response', function () {
    $api = DatatableApi::for(TestUser::query(), datatableRequest([]))->returnResource(JsonResource::class);

    $payload = json_decode(json_encode($api), true);

    expect($payload['meta']['total'])->toBe(2)
        ->and($payload['data'])->toHaveCount(2);
});

it('searches for the term "0"', function () {
    TestUser::create(['first_name' => 'Zero', 'last_name' => 'X', 'email' => 'a0@test']);

    $result = DatatableApi::for(TestUser::query(), datatableRequest(['search' => '0']))
        ->withSearchableColumns(['email'])
        ->toPaginator();

    expect($result->total())->toBe(1);
});

it('builds pagination links from the explicit request URL', function () {
    app()->instance('request', Request::create('/somewhere-else'));

    $result = DatatableApi::for(TestUser::query(), Request::create('/users', 'GET', ['per_page' => 1]))->toPaginator();

    expect($result->path())->toBe('http://localhost/users')
        ->and($result->nextPageUrl())->toBe('http://localhost/users?page=2');
});

it('keeps a custom current path resolver when no request is passed', function () {
    Paginator::currentPathResolver(fn () => 'https://proxy.test/users');

    $result = DatatableApi::for(TestUser::query())->toPaginator();

    expect($result->path())->toBe('https://proxy.test/users');
});
