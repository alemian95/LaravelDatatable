<?php

use AleMian95\Datatable\Concerns\HasSearchableColumns as HasSearchableColumnsTrait;
use AleMian95\Datatable\Contracts\HasSearchableColumns;
use AleMian95\Datatable\Tests\Fixtures\Models\TestUser;
use Illuminate\Support\Facades\Log;

class TraitTestUserWithProperty extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    protected array $searchable = ['first_name', 'email'];
}

class TraitTestUserWithEmptyProperty extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    protected array $searchable = [];
}

class TraitTestUserWithoutProperty extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    // No $searchable property — simulates a developer typo or oversight.
}

class TraitTestUserWithWrongType extends TestUser implements HasSearchableColumns
{
    use HasSearchableColumnsTrait;

    protected $table = 'test_users';

    /** @phpstan-ignore-next-line — intentional wrong type for the test */
    protected $searchable = 'first_name'; // string instead of array
}

it('returns the declared columns when the property is a non-empty array', function () {
    Log::shouldReceive('warning')->never();

    $model = new TraitTestUserWithProperty;

    expect($model->getSearchableColumns())->toBe(['first_name', 'email']);
});

it('returns an empty array WITHOUT warning when the property is a deliberate empty array', function () {
    Log::shouldReceive('warning')->never();

    $model = new TraitTestUserWithEmptyProperty;

    expect($model->getSearchableColumns())->toBe([]);
});

it('logs a warning and returns empty when the $searchable property is missing', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/does not define a \$searchable property/'));

    $model = new TraitTestUserWithoutProperty;

    expect($model->getSearchableColumns())->toBe([]);
});

it('logs a warning and returns empty when the $searchable property has the wrong type', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with(Mockery::pattern('/must be an array, got string/'));

    $model = new TraitTestUserWithWrongType;

    expect($model->getSearchableColumns())->toBe([]);
});
