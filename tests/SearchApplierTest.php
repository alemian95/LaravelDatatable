<?php

use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;
use AleMian95\Datatable\Contracts\HasSearchableColumns;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\SearchApplier;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Http\Request;

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
