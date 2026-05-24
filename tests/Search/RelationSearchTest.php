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
    $hasOneQuery->where(fn ($q) => RelationSearch::hasOne('posts')->apply($q, 'users', 'title', 'jane')
    );

    $hasManyQuery = DB::table('users');
    $hasManyQuery->where(fn ($q) => RelationSearch::hasMany('posts')->apply($q, 'users', 'title', 'jane')
    );

    expect($hasManyQuery->toRawSql())->toBe($hasOneQuery->toRawSql());
});

it('belongsToMany applies EXISTS with pivot inner join and default keys', function () {
    $query = DB::table('users');
    $spec = RelationSearch::belongsToMany('roles', pivot: 'role_user');

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'label', 'admin');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('from "roles"')
        ->toContain('inner join "role_user"')
        ->toContain('"role_user"."role_id" = "roles"."id"')
        ->toContain('"role_user"."user_id" = "users"."id"')
        ->toContain('"roles"."label"');
});

it('belongsToMany respects custom pivot key overrides', function () {
    $query = DB::table('users');
    $spec = RelationSearch::belongsToMany(
        'roles',
        pivot: 'role_user',
        foreignPivotKey: 'u_id',
        relatedPivotKey: 'r_id',
    );

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'label', 'admin');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('"role_user"."r_id" = "roles"."id"')
        ->toContain('"role_user"."u_id" = "users"."id"');
});

it('belongsToMany respects custom parentKey and relatedKey', function () {
    $query = DB::table('users');
    $spec = RelationSearch::belongsToMany(
        'roles',
        pivot: 'role_user',
        parentKey: 'uuid',
        relatedKey: 'slug',
    );

    $query->where(function ($q) use ($spec) {
        $spec->apply($q, 'users', 'label', 'admin');
    });

    $sql = $query->toRawSql();

    expect($sql)
        ->toContain('"role_user"."role_id" = "roles"."slug"')
        ->toContain('"role_user"."user_id" = "users"."uuid"');
});

it('custom invokes the user closure with 3 args (query, remoteColumn, term)', function () {
    $received = null;
    $spec = RelationSearch::custom(function (...$args) use (&$received) {
        $received = $args;
    });

    $query = DB::table('books');
    $spec->apply($query, 'books', 'name', 'jane');

    expect($received)->toHaveCount(3);
    expect($received[1])->toBe('name');
    expect($received[2])->toBe('jane');
});

it('custom does not pass baseTable to the user closure', function () {
    $argCount = null;
    $spec = RelationSearch::custom(function ($query, $remoteColumn, $term) use (&$argCount) {
        $argCount = func_num_args();
    });

    $spec->apply(DB::table('books'), 'books', 'name', 'jane');

    expect($argCount)->toBe(3);
});

it('custom allows the user closure to mutate the query freely', function () {
    $spec = RelationSearch::custom(function ($query, $remoteColumn, $term) {
        $query->orWhere('static_marker', 'set-by-custom');
    });

    $query = DB::table('books');
    $query->where(fn ($q) => $spec->apply($q, 'books', 'name', 'jane'));

    expect($query->toRawSql())->toContain("'set-by-custom'");
});
