<?php

namespace AleMian95\Datatable\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AleMian95\Datatable\Datatable
 */
class Datatable extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AleMian95\Datatable\Datatable::class;
    }
}
