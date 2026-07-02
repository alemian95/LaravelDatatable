<?php

use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\SortApplier;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

function makeSortRequest(array $params = []): DatatableRequest
{
    return DatatableRequest::fromRequest(Request::create('/', 'GET', $params));
}

it('skips sorting when the request has no sort_by', function () {
    $builder = TestUser::query();

    (new SortApplier)->apply($builder, makeSortRequest());

    expect($builder->toSql())->not->toContain('order by');
});

it('sorts by any column when no whitelist is declared (legacy behavior)', function () {
    $builder = TestUser::query();

    (new SortApplier)->apply($builder, makeSortRequest(['sort_by' => 'email', 'sort_order' => 'desc']));

    expect($builder->toSql())->toContain('order by')->toContain('"email"');
});

it('applies the sort when sort_by is inside the declared whitelist', function () {
    $builder = TestUser::query();

    (new SortApplier([], ['first_name', 'email']))->apply($builder, makeSortRequest(['sort_by' => 'email']));

    expect($builder->toSql())->toContain('order by')->toContain('"email"');
});

it('drops the sort with a warning when sort_by is outside the whitelist', function () {
    Log::shouldReceive('warning')->once();

    $builder = TestUser::query();

    (new SortApplier([], ['first_name']))->apply($builder, makeSortRequest(['sort_by' => 'password']));

    expect($builder->toSql())->not->toContain('order by');
});

it('always allows a custom sort key regardless of the whitelist', function () {
    $called = false;
    $custom = [
        'full_name' => function ($builder, $direction) use (&$called): void {
            $called = true;
            $builder->orderByRaw("first_name {$direction}");
        },
    ];

    $builder = TestUser::query();

    (new SortApplier($custom, ['email']))->apply($builder, makeSortRequest(['sort_by' => 'full_name']));

    expect($called)->toBeTrue();
});
