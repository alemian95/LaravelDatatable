<?php

namespace AleMian95\Datatable\Contracts;

use AleMian95\Datatable\DatatableRequest;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * @internal Not covered by semver; see docs/adr/0002-public-api-boundary.md.
 */
interface QueryApplier
{
    public function apply(Builder $builder, DatatableRequest $request): void;
}
