<?php

use AleMian95\Datatable\Search\Sources\ApiDeclaredColumnSource;

it('returns the columns passed in', function () {
    $source = new ApiDeclaredColumnSource();

    expect($source->columns(['name', 'email']))->toBe(['name', 'email']);
});

it('returns an empty array when given null', function () {
    $source = new ApiDeclaredColumnSource();

    expect($source->columns(null))->toBe([]);
});

it('returns an empty array when given an empty array', function () {
    $source = new ApiDeclaredColumnSource();

    expect($source->columns([]))->toBe([]);
});
