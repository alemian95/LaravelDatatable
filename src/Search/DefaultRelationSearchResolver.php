<?php

namespace AleMian95\Datatable\Search;

use AleMian95\Datatable\Contracts\RelationSearchResolver as Contract;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class DefaultRelationSearchResolver implements Contract
{
    public function resolve(Builder $builder, string $relationKey, array $apiDeclaredMap): ?RelationSearch
    {
        if (isset($apiDeclaredMap[$relationKey])) {
            return $apiDeclaredMap[$relationKey];
        }

        if (! $builder instanceof EloquentBuilder) {
            return null;
        }

        $model = $builder->getModel();

        if (! method_exists($model, $relationKey)) {
            return null;
        }

        // Auto-discovery for relation types added in Task 6.
        return null;
    }
}
