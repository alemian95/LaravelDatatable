<?php

namespace AleMian95\Datatable\Search;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * @internal
 */
final class SpecDottedEntry implements DottedEntry
{
    public function __construct(
        private readonly RelationSearch $spec,
        private readonly string $remoteColumn,
    ) {}

    public function apply(Builder $query, string $baseTable, string $baseAlias, string $term): void
    {
        $this->spec->apply($query, $baseTable, $this->remoteColumn, $term, $baseAlias);
    }
}
