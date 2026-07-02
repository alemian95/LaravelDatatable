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
