<?php

use AleMian95\Datatable\Search\Sources\ApiDeclaredColumnSource;

it('returns the columns passed in', function () {
    $source = new ApiDeclaredColumnSource();

    expect($source->columns(['name', 'email']))->toBe(['name', 'email']);
});

it('returns null when given null (no source opinion)', function () {
    $source = new ApiDeclaredColumnSource();

    expect($source->columns(null))->toBeNull();
});

it('preserves an empty array as an authoritative empty whitelist', function () {
    $source = new ApiDeclaredColumnSource();

    expect($source->columns([]))->toBe([]);
});
