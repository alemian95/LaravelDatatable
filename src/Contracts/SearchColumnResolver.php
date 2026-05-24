<?php

namespace AleMian95\Datatable\Contracts;

use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\Exceptions\SearchColumnsNotConfiguredException;
use Illuminate\Contracts\Database\Query\Builder;

interface SearchColumnResolver
{
    /**
     * Resolve the effective list of columns to search on.
     *
     * @param  array<int, string>|null  $apiDeclaredColumns  Columns passed via DatatableApi::withSearchableColumns()
     * @return array<int, string>  Empty array means: do not apply any search clause.
     *
     * @throws SearchColumnsNotConfiguredException
     */
    public function resolve(
        Builder $builder,
        DatatableRequest $request,
        ?array $apiDeclaredColumns,
    ): array;
}
