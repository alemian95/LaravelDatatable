<?php

use AleMian95\Datatable\DatatableApi;
use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\KeyTiebreakerApplier;
use AleMian95\Datatable\Tests\Fixtures\Models\TestPost;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function applyTiebreaker($builder): string
{
    (new KeyTiebreakerApplier)->apply($builder, DatatableRequest::fromRequest(Request::create('/')));

    return $builder->toSql();
}

it('orders by the qualified primary key after the existing orders', function () {
    expect(applyTiebreaker(TestUser::query()->orderBy('last_name')))
        ->toEndWith('order by "last_name" asc, "test_users"."id" asc');
});

it('orders by the primary key when the query has no order', function () {
    expect(applyTiebreaker(TestUser::query()))->toEndWith('order by "test_users"."id" asc');
});

it('qualifies the key with the from alias', function () {
    expect(applyTiebreaker(TestUser::query()->from('test_users as u')))
        ->toEndWith('order by "u"."id" asc');
});

it('does not add the key when the query already orders by it', function (string $column) {
    expect(applyTiebreaker(TestUser::query()->orderBy($column, 'desc')))
        ->toEndWith('order by "'.str_replace('.', '"."', $column).'" desc');
})->with(['id', 'test_users.id']);

it('uses the related key on a relation builder', function () {
    $user = TestUser::create(['first_name' => 'Ann', 'last_name' => 'B', 'email' => 'ann@test']);

    expect(applyTiebreaker($user->posts()))->toEndWith('order by "test_posts"."id" asc');
});

it('leaves raw queries, grouped queries and subquery froms alone', function ($builder) {
    expect(applyTiebreaker($builder))->not->toContain('order by');
})->with([
    'raw' => fn () => DB::table('test_users'),
    'grouped' => fn () => TestPost::query()->select('test_user_id')->groupBy('test_user_id'),
    'subquery from' => fn () => TestUser::query()->fromSub(DB::table('test_users'), 'sub'),
    'distinct' => fn () => TestUser::query()->select('last_name')->distinct(),
    'union' => fn () => TestUser::query()->union(TestUser::query()->where('id', 1)),
]);

it('is applied by DatatableApi after the client sort', function () {
    TestUser::create(['first_name' => 'Ann', 'last_name' => 'B', 'email' => 'ann@test']);
    $this->app->instance('request', Request::create('/', 'GET', ['sort_by' => 'last_name']));
    DB::enableQueryLog();

    DatatableApi::for(TestUser::query())->jsonSerialize();

    expect(collect(DB::getQueryLog())->last()['query'])
        ->toContain('order by "last_name" asc, "test_users"."id" asc');
});
