<?php

namespace AleMian95\Datatable\Contracts;

use AleMian95\Datatable\DatatableRequest;
use Illuminate\Contracts\Database\Query\Builder;

interface QueryApplier
{
    public function apply(Builder $builder, DatatableRequest $request): void;
}
