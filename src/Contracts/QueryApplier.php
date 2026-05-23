<?php

namespace AleMian95\Datatable\Contracts;

use Illuminate\Contracts\Database\Query\Builder;
use AleMian95\Datatable\DatatableRequest;

interface QueryApplier
{
    public function apply(Builder $builder, DatatableRequest $request): void;
}
