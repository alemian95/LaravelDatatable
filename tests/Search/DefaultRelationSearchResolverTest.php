<?php

use AleMian95\Datatable\Search\DefaultRelationSearchResolver;
use AleMian95\Datatable\Search\RelationSearch;
use AleMian95\Datatable\Tests\Fixtures\Models\TestPost;
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
