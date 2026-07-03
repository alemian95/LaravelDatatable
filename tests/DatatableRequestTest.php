<?php

use AleMian95\Datatable\DatatableRequest;
use Illuminate\Http\Request;

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
