<?php

namespace AleMian95\Datatable;

use Illuminate\Contracts\Database\Query\Builder;
use AleMian95\Datatable\Contracts\QueryApplier;

class FilterApplier implements QueryApplier
{
    /**
     * @param  array<\Closure>  $filters
     */
    public function __construct(protected array $filters) {}

    public function apply(Builder $builder, DatatableRequest $request): void
    {
        foreach ($this->filters as $filter) {
            $filter($builder);
        }
    }
}
