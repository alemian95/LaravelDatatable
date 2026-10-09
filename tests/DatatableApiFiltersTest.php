<?php

use AleMian95\Datatable\DatatableApi;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    TestUser::create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@test']);
    TestUser::create(['first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@test']);
});

function filtered(array $params): DatatableApi
{
    return DatatableApi::for(TestUser::query(), Request::create('/', 'GET', $params));
}

it('applies a declared filter with the parsed value', function () {
    $result = filtered(['filter' => ['last_name' => 'Doe']])
        ->withFilters(['last_name' => fn ($q, string $value) => $q->where('last_name', $value)])
        ->toPaginator();

    expect($result->total())->toBe(1);
});

it('passes a range value as from/to', function () {
    $received = null;

    filtered(['filter' => ['created_at' => ['from' => '2026-01-01']]])
        ->withFilters(['created_at' => function ($q, array $value) use (&$received): void {
            $received = $value;
        }])
        ->toPaginator();

    expect($received)->toBe(['from' => '2026-01-01', 'to' => null]);
});

it('does not call a declared filter when its key is absent', function () {
    $called = false;

    filtered([])->withFilters(['status' => function () use (&$called): void {
        $called = true;
    }])->toPaginator();

    expect($called)->toBeFalse();
});

it('ignores an undeclared filter key with a warning', function () {
    Log::spy();

    $result = filtered(['filter' => ['password' => 'x']])->withFilters([])->toPaginator();

    expect($result->total())->toBe(2);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '[password]'))->once();
});

it('replaces filters on a second withFilters() call', function () {
    $result = filtered(['filter' => ['last_name' => 'Doe']])
        ->withFilters(['last_name' => fn ($q, $v) => $q->whereRaw('1 = 0')])
        ->withFilters(['last_name' => fn ($q, $v) => $q->where('last_name', $v)])
        ->toPaginator();

    expect($result->total())->toBe(1);
});

it('warns about a malformed filter value', function () {
    Log::spy();

    filtered(['filter' => ['tags' => ['a', 'b']]])->withFilters([])->toPaginator();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '[tags]'))->once();
});

it('reports every dropped filter key in one warning per request', function () {
    Log::spy();

    filtered(['filter' => ['a' => 'x', 'b' => 'y', 'c' => ['z']]])->withFilters([])->toPaginator();

    Log::shouldHaveReceived('warning')->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '[a, b, c]'));
});

it('drops a range sent to a filter typed string, with a warning', function () {
    Log::spy();
    $called = false;

    $total = filtered(['filter' => ['last_name' => ['from' => 'a']]])
        ->withFilters(['last_name' => function ($q, string $value) use (&$called) {
            $called = true;
        }])
        ->toPaginator()
        ->total();

    expect($called)->toBeFalse()->and($total)->toBe(2);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '[last_name]'))->once();
});

it('drops a single value sent to a filter typed array, with a warning', function () {
    Log::spy();
    $called = false;

    filtered(['filter' => ['created_at' => '2026-01-01']])
        ->withFilters(['created_at' => function ($q, array $range) use (&$called) {
            $called = true;
        }])
        ->toPaginator();

    expect($called)->toBeFalse();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '[created_at]'))->once();
});

it('passes any value shape to an untyped filter', function () {
    $received = [];
    $filter = function ($q, $value) use (&$received) {
        $received[] = $value;
    };

    filtered(['filter' => ['a' => 'x', 'b' => ['to' => '2026-01-01']]])
        ->withFilters(['a' => $filter, 'b' => $filter])
        ->toPaginator();

    expect($received)->toBe(['x', ['from' => null, 'to' => '2026-01-01']]);
});

it('calls a filter that ignores the value', function () {
    $result = filtered(['filter' => ['doe' => '1']])
        ->withFilters(['doe' => fn ($q) => $q->where('last_name', 'Doe')])
        ->toPaginator();

    expect($result->total())->toBe(1);
});
