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

it('hasOne applies EXISTS with default foreignKey derived from baseTable', function () {
    $query = DB::table('users');
    $spec = RelationSearch::hasOne('profiles');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'bio', 'jane');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('from "profiles"')
        ->toContain('"profiles"."user_id" = "users"."id"')
        ->toContain('"profiles"."bio"');
});

it('hasOne respects custom foreignKey and localKey', function () {
    $query = DB::table('users');
    $spec = RelationSearch::hasOne('profiles', foreignKey: 'u_id', localKey: 'uuid');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'bio', 'jane');
    });

    expect($query->toRawSql())
        ->toContain('"profiles"."u_id" = "users"."uuid"');
});

it('hasMany produces the same SQL shape as hasOne', function () {
    $hasOneQuery = DB::table('users');
    $hasOneQuery->where(fn ($q) =>
        RelationSearch::hasOne('posts')->apply($q, 'users', 'title', 'jane')
    );

    $hasManyQuery = DB::table('users');
    $hasManyQuery->where(fn ($q) =>
        RelationSearch::hasMany('posts')->apply($q, 'users', 'title', 'jane')
    );

    expect($hasManyQuery->toRawSql())->toBe($hasOneQuery->toRawSql());
});
