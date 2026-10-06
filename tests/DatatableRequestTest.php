<?php

use AleMian95\Datatable\DatatableRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

function makePerPageRequest(array $params = []): DatatableRequest
{
    return DatatableRequest::fromRequest(Request::create('/', 'GET', $params));
}

it('falls back to the config default per_page when omitted', function () {
    expect(makePerPageRequest()->perPage)->toBe(15);
});

it('clamps per_page down to max_per_page', function () {
    expect(makePerPageRequest(['per_page' => 1_000_000])->perPage)->toBe(100);
});

it('floors per_page at 1', function () {
    expect(makePerPageRequest(['per_page' => 0])->perPage)->toBe(1)
        ->and(makePerPageRequest(['per_page' => -5])->perPage)->toBe(1);
});

it('passes a per_page within bounds through unchanged', function () {
    expect(makePerPageRequest(['per_page' => 25])->perPage)->toBe(25);
});

it('defaults sort_order to asc and lowercases valid values', function () {
    expect(makePerPageRequest()->sortOrder)->toBe('asc')
        ->and(makePerPageRequest(['sort_order' => 'DESC'])->sortOrder)->toBe('desc');
});

it('falls back to asc for an invalid or non-string sort_order', function () {
    expect(makePerPageRequest(['sort_order' => 'foo; drop table users'])->sortOrder)->toBe('asc')
        ->and(makePerPageRequest(['sort_order' => ['desc']])->sortOrder)->toBe('asc');
});

it('coerces array search and sort_by to null instead of raising a TypeError', function () {
    $request = makePerPageRequest(['search' => ['a', 'b'], 'sort_by' => ['x']]);

    expect($request->search)->toBeNull()
        ->and($request->sortBy)->toBeNull();
});

it('reads page, defaulting and flooring to 1', function () {
    expect(makePerPageRequest()->page)->toBe(1)
        ->and(makePerPageRequest(['page' => 3])->page)->toBe(3)
        ->and(makePerPageRequest(['page' => 0])->page)->toBe(1)
        ->and(makePerPageRequest(['page' => 'abc'])->page)->toBe(1);
});

it('parses scalar and range filters', function () {
    $request = makePerPageRequest(['filter' => [
        'status' => 'active',
        'created_at' => ['from' => '2026-01-01'],
        'price' => ['from' => '10', 'to' => '20'],
    ]]);

    expect($request->filters)->toBe([
        'status' => 'active',
        'created_at' => ['from' => '2026-01-01', 'to' => null],
        'price' => ['from' => '10', 'to' => '20'],
    ]);
});

it('skips empty filter values silently', function () {
    Log::spy();

    $request = makePerPageRequest(['filter' => ['status' => '', 'role' => null]]);

    expect($request->filters)->toBe([]);
    Log::shouldNotHaveReceived('warning');
});

it('drops malformed filter values and records their keys without logging', function (array $filter, string $key) {
    Log::spy();

    $request = makePerPageRequest(['filter' => $filter]);

    expect($request->filters)->toBe([])
        ->and($request->malformedFilters)->toBe([$key]);
    Log::shouldNotHaveReceived('warning');
})->with([
    'list' => [['s' => ['a', 'b']], 's'],
    'nested bound' => [['r' => ['from' => ['x' => '1']]], 'r'],
    'unknown range key' => [['r' => ['other' => '1']], 'r'],
    'empty range' => [['r' => ['from' => '', 'to' => '']], 'r'],
]);

it('ignores a filter parameter that is not an array', function () {
    expect(makePerPageRequest(['filter' => 'status'])->filters)->toBe([]);
});

it('ignores array values for every scalar parameter', function () {
    $request = makePerPageRequest([
        'search' => ['a'],
        'search_columns' => ['first_name'],
        'sort_by' => ['email'],
        'sort_order' => ['desc'],
        'per_page' => ['50'],
        'page' => ['2'],
    ]);

    expect($request->search)->toBeNull()
        ->and($request->searchColumns)->toBe([])
        ->and($request->sortBy)->toBeNull()
        ->and($request->sortOrder)->toBe('asc')
        ->and($request->perPage)->toBe(15)
        ->and($request->page)->toBe(1);
});
