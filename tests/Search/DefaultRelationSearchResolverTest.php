<?php

use AleMian95\Datatable\Search\DefaultRelationSearchResolver;
use AleMian95\Datatable\Search\RelationSearch;
use AleMian95\Datatable\Tests\Fixtures\Models\TestPost;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Support\Facades\DB;

it('returns the declared spec when the map contains the relation key', function () {
    $resolver = new DefaultRelationSearchResolver;
    $spec = RelationSearch::belongsTo('authors');

    $result = $resolver->resolve(
        DB::table('books'),
        'author',
        ['author' => $spec],
    );

    expect($result)->toBe($spec);
});

it('returns null on raw QueryBuilder when the map is empty', function () {
    $resolver = new DefaultRelationSearchResolver;

    $result = $resolver->resolve(DB::table('books'), 'author', []);

    expect($result)->toBeNull();
});

it('returns null on Eloquent when the relation method does not exist', function () {
    $resolver = new DefaultRelationSearchResolver;

    $result = $resolver->resolve(TestPost::query(), 'nonexistent', []);

    expect($result)->toBeNull();
});

it('auto-discovers a BelongsTo relation on Eloquent', function () {
    // TestPost::author() returns belongsTo(TestUser::class, 'test_user_id')
    $resolver = new DefaultRelationSearchResolver;

    $spec = $resolver->resolve(TestPost::query(), 'author', []);

    expect($spec)->toBeInstanceOf(RelationSearch::class);

    $query = TestPost::query();
    $query->where(fn ($q) => $spec->apply($q, 'test_posts', 'first_name', 'jane'));
    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('from "test_users"')
        ->toContain('"test_users"."id" = "test_posts"."test_user_id"')
        ->toContain('"test_users"."first_name"');
});

it('auto-discovers a HasMany relation on Eloquent', function () {
    // TestUser::posts() returns hasMany(TestPost::class, 'test_user_id')
    $resolver = new DefaultRelationSearchResolver;

    $spec = $resolver->resolve(TestUser::query(), 'posts', []);

    expect($spec)->toBeInstanceOf(RelationSearch::class);

    $query = TestUser::query();
    $query->where(fn ($q) => $spec->apply($q, 'test_users', 'title', 'hello'));
    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('from "test_posts"')
        ->toContain('"test_posts"."test_user_id" = "test_users"."id"')
        ->toContain('"test_posts"."title"');
});

it('explicit declaration wins over auto-discovery for the same relation key', function () {
    $override = RelationSearch::belongsTo('overridden_table');
    $resolver = new DefaultRelationSearchResolver;

    $spec = $resolver->resolve(TestPost::query(), 'author', ['author' => $override]);

    expect($spec)->toBe($override);
});

it('returns null when the named method exists but is not a Relation', function () {
    // TestPost::getKey() exists on every Eloquent model and returns the primary key.
    // It is NOT a Relation instance — the resolver must not crash and must return null.
    $resolver = new DefaultRelationSearchResolver;

    $result = $resolver->resolve(TestPost::query(), 'getKey', []);

    expect($result)->toBeNull();
});
