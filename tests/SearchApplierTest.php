<?php

use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;
use AleMian95\Datatable\Contracts\HasSearchableColumns;
use AleMian95\Datatable\Contracts\RelationSearchResolver;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\Search\RelationSearch;
use AleMian95\Datatable\SearchApplier;
use AleMian95\Datatable\Tests\Fixtures\Models\TestPost;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApplierSearchableUser extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    protected array $searchable = ['first_name', 'email'];
}

function makeApplierRequest(array $params = []): DatatableRequest
{
    return DatatableRequest::fromRequest(Request::create('/', 'GET', $params));
}

it('skips the search when the request has no search term', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldNotReceive('resolve');

    $applier = new SearchApplier($resolver);
    $builder = ApplierSearchableUser::query();

    $applier->apply($builder, makeApplierRequest());

    expect($builder->toSql())->not->toContain('like');
});

it('runs the customSearch closure when provided, bypassing the resolver', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldNotReceive('resolve');

    $called = false;
    $applier = new SearchApplier($resolver, function ($builder, $term) use (&$called): void {
        $called = true;
        $builder->where('first_name', $term);
    });

    $builder = ApplierSearchableUser::query();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($called)->toBeTrue();
    expect($builder->toSql())->toContain('"first_name"');
});

it('applies LIKE clauses on the columns returned by the resolver', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn(['first_name', 'email']);

    $applier = new SearchApplier($resolver);
    $builder = ApplierSearchableUser::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toSql());
    expect($sql)->toContain('"first_name"');
    expect($sql)->toContain('"email"');
    expect($sql)->toContain('like');
});

it('does not add any WHERE clause when the resolver returns an empty array', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn([]);

    $applier = new SearchApplier($resolver);
    $builder = ApplierSearchableUser::query();

    $beforeSql = $builder->toSql();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toSql())->toBe($beforeSql);
});

it('passes the apiDeclaredColumns through to the resolver', function () {
    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldReceive('resolve')
        ->once()
        ->withArgs(fn ($b, $r, $api) => $api === ['email'])
        ->andReturn(['email']);

    $applier = new SearchApplier($resolver, null, ['email']);
    $builder = ApplierSearchableUser::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect(true)->toBeTrue(); // assertion is in the mock expectations
});

it('logs a warning and drops dotted entries when the builder is a raw QueryBuilder', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/dropped dotted columns \[posts\.title\]/'));

    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn(['first_name', 'posts.title']);

    $applier = new SearchApplier($resolver);
    $builder = DB::table('test_users');

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toSql());
    expect($sql)->toContain('"first_name"');
    expect($sql)->not->toContain('posts');
});

it('skips the WHERE clause entirely when every resolved column is dotted on a raw QueryBuilder', function () {
    Log::shouldReceive('warning')->once();

    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn(['posts.title', 'comments.body']);

    $applier = new SearchApplier($resolver);
    $builder = DB::table('test_users');

    $beforeSql = $builder->toSql();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toSql())->toBe($beforeSql);
});

it('processes dotted entries normally on an Eloquent builder without logging a warning', function () {
    Log::shouldReceive('warning')->never();

    $resolver = Mockery::mock(SearchColumnResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn(['first_name', 'posts.title']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($resolver, null, null, $relationResolver, []);
    $builder = ApplierSearchableUser::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toSql());
    expect($sql)->toContain('"first_name"');
    expect($sql)->toContain('exists');
    expect($sql)->toContain('"test_posts"'); // EXISTS subquery against test_posts
});

it('emits orWhereExists for a single-hop dotted column on raw with a declared spec', function () {
    Log::shouldReceive('warning')->never();

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;
    $map = ['author' => RelationSearch::belongsTo('test_users', localKey: 'test_user_id')];

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, $map);
    $builder = DB::table('test_posts');

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    expect($sql)
        ->toContain('exists')
        ->toContain('"test_users"."id" = "test_posts"."test_user_id"')
        ->toContain('"test_users"."first_name"')
        ->toContain("'%jane%'");
});

it('drops a single-hop dotted column on raw when no declared spec is available', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/author\.first_name/'));

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = DB::table('test_posts');

    $beforeSql = $builder->toSql();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toSql())->toBe($beforeSql);
});

it('emits orWhereExists for a single-hop dotted column on Eloquent via auto-discovery', function () {
    Log::shouldReceive('warning')->never();

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = TestPost::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    expect($sql)
        ->toContain('exists')
        ->toContain('"test_users"."id" = "test_posts"."test_user_id"')
        ->toContain('"test_users"."first_name"');
});

it('declared map overrides Eloquent auto-discovery for the same relation key', function () {
    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;
    $overrideMarker = RelationSearch::custom(function ($query, $remoteColumn, $term) {
        $query->orWhere('manual_marker', '=', "{$remoteColumn}:{$term}");
    });

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, ['author' => $overrideMarker]);
    $builder = TestPost::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toRawSql())
        ->toContain('"manual_marker"')
        ->toContain("'first_name:jane'");
});

it('combines a flat column and a single-hop dotted column in one nested WHERE', function () {
    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['title', 'author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = TestPost::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    expect($sql)
        ->toContain('"title" like')
        ->toContain('exists')
        ->toContain('"test_users"."first_name"');
});

it('preserves the legacy orWhereHas path for multi-hop Eloquent without a declared spec', function () {
    Log::shouldReceive('warning')->never();

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.posts.title']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = TestPost::query();

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    // orWhereHas('author.posts', ...) generates nested EXISTS:
    // outer on test_users (author), inner on test_posts with "title" like ...
    expect($sql)
        ->toContain('exists')
        ->toContain('"test_users"')
        ->toContain('"test_posts"')
        ->toContain('"title" like');
});

it('drops a multi-hop dotted column on raw with a warning', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/author\.posts\.title/'));

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.posts.title']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, []);
    $builder = DB::table('test_posts');

    $beforeSql = $builder->toSql();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toSql())->toBe($beforeSql);
});

it('drops dotted columns and warns when the base table cannot be inferred (subquery from)', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/author\.first_name.*cannot infer base table/i'));

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['first_name', 'author.first_name']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;
    $map = ['author' => RelationSearch::belongsTo('test_users', localKey: 'test_user_id')];

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, $map);

    // Build a raw query whose `from` is a subquery — not a plain string identifier.
    $sub = DB::table('test_posts');
    $builder = DB::query()->fromSub($sub, 't');

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    // Flat column applied; dotted columns dropped (no EXISTS, no test_users)
    expect($sql)->toContain('"first_name" like');
    expect($sql)->not->toContain('exists');
    expect($sql)->not->toContain('test_users');
});

it('strips a table alias from baseTable so default-key derivation works on aliased raw queries', function () {
    Log::shouldReceive('warning')->never();

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['posts.title']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;
    // hasOne defaults: foreignKey = Str::singular($baseTable) . '_id'. If $baseTable
    // arrived as 'test_users as u', singularization would yield 'test_users as u_id' —
    // a malformed identifier. The applier must strip the alias before passing it.
    $map = ['posts' => RelationSearch::hasOne('test_posts', foreignKey: 'test_user_id')];

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, $map);
    $builder = DB::table('test_users as u');

    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    $sql = strtolower($builder->toRawSql());

    expect($sql)
        ->toContain('"test_posts"."test_user_id" = "test_users"."id"')
        ->toContain('"test_posts"."title"');
});

it('drops a multi-hop dotted column on raw even when a single-segment spec is declared', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/author\.posts\.title.*multi-hop/i'));

    $columnResolver = Mockery::mock(SearchColumnResolver::class);
    $columnResolver->shouldReceive('resolve')->once()->andReturn(['author.posts.title']);

    $relationResolver = new \AleMian95\Datatable\Search\DefaultRelationSearchResolver;
    $map = ['author' => RelationSearch::belongsTo('test_users', localKey: 'test_user_id')];

    $applier = new SearchApplier($columnResolver, null, null, $relationResolver, $map);
    $builder = DB::table('test_posts');

    $beforeSql = $builder->toSql();
    $applier->apply($builder, makeApplierRequest(['search' => 'jane']));

    expect($builder->toSql())->toBe($beforeSql);
});
