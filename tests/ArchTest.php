<?php

use AleMian95\Datatable\DatatableApi;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('DatatableApi is final')
    ->expect(DatatableApi::class)
    ->toBeFinal();
