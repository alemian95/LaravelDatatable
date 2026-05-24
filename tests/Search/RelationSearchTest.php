<?php

use AleMian95\Datatable\Search\RelationSearch;
use Illuminate\Support\Facades\DB;

it('belongsTo applies orWhereExists with default Laravel keys', function () {
    $query = DB::table('books');
    $spec = RelationSearch::belongsTo('authors');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'books', 'name', 'jane');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('exists')
        ->toContain('from "authors"')
        ->toContain('"authors"."id" = "books"."author_id"')
        ->toContain('"authors"."name"')
        ->toContain("'%jane%'");
});

it('belongsTo respects custom localKey and remoteKey', function () {
    $query = DB::table('books');
    $spec = RelationSearch::belongsTo('writers', localKey: 'written_by', remoteKey: 'uuid');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'books', 'name', 'jane');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('"writers"."uuid" = "books"."written_by"')
        ->toContain('"writers"."name"');
});

it('belongsTo binds the search term as a parameter (no SQL injection on apostrophes)', function () {
    $query = DB::table('books');
    $spec = RelationSearch::belongsTo('authors');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'books', 'name', "o'brien");
    });

    expect($query->getBindings())->toContain("%o'brien%");
});
