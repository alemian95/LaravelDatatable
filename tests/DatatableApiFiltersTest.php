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

function withoutDeprecations(Closure $run): mixed
{
    set_error_handler(fn (): bool => true, E_USER_DEPRECATED);

    try {
        return $run();
    } finally {
        restore_error_handler();
    }
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

it('keeps withCustomFilters() working, replacing on each call, and flags it', function () {
    $messages = [];
    set_error_handler(function (int $level, string $message) use (&$messages): bool {
        $messages[] = $message;

        return true;
    }, E_USER_DEPRECATED);

    try {
        $result = filtered([])
            ->withCustomFilters([fn ($q) => $q->whereRaw('1 = 0')])
            ->withCustomFilters([fn ($q) => $q->where('first_name', 'Jane')])
            ->toPaginator();
    } finally {
        restore_error_handler();
    }

    expect($result->total())->toBe(1)
        ->and($messages[0])->toContain('withFilters()');
});

it('stays quiet about undeclared filter keys while legacy filters are set', function () {
    Log::spy();

    withoutDeprecations(fn () => filtered(['filter' => ['status' => 'x']])
        ->withCustomFilters([fn ($q) => $q])
        ->toPaginator());

    Log::shouldNotHaveReceived('warning');
});

it('warns about a malformed filter value when no legacy filters are set', function () {
    Log::spy();

    filtered(['filter' => ['tags' => ['a', 'b']]])->withFilters([])->toPaginator();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, '[tags]'))->once();
});

it('stays quiet about malformed filter values while legacy filters may read them', function () {
    Log::spy();

    withoutDeprecations(fn () => filtered(['filter' => ['tags' => ['a', 'b']]])
        ->withCustomFilters([fn ($q) => $q])
        ->toPaginator());

    Log::shouldNotHaveReceived('warning');
});
