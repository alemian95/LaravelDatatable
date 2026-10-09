<?php

use AleMian95\Datatable\DatatableApi;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('DatatableApi is final')
    ->expect(DatatableApi::class)
    ->toBeFinal();

arch('source files declare strict types')
    ->expect('AleMian95\Datatable')
    ->toUseStrictTypes();

test('the 0.9 deprecations are gone and for() is the only way in', function () {
    $api = new ReflectionClass(DatatableApi::class);

    expect($api->hasMethod('fromQuery'))->toBeFalse()
        ->and($api->hasMethod('withCustomFilters'))->toBeFalse()
        ->and($api->getConstructor()?->isPrivate())->toBeTrue();
});
