<?php

namespace AleMian95\Datatable\Contracts;

use AleMian95\Datatable\Search\RelationSearch;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * @internal Not covered by semver; see docs/adr/0002-public-api-boundary.md.
 */
interface RelationSearchResolver
{
    /**
     * Resolve which RelationSearch handles a given relation segment for this builder,
     * or null if no source can satisfy it (caller drops the dotted column + logs warning).
     *
     * @param  array<string, RelationSearch>  $apiDeclaredMap
     */
    public function resolve(Builder $builder, string $relationKey, array $apiDeclaredMap): ?RelationSearch;
}
