<?php

namespace AleMian95\Datatable\Search;

use AleMian95\Datatable\Contracts\RelationSearchResolver as Contract;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

        $relation = $model->{$relationKey}();

        return match (true) {
            $relation instanceof BelongsTo     => RelationSearch::belongsTo(
                table: $relation->getRelated()->getTable(),
                localKey: $relation->getForeignKeyName(),
                remoteKey: $relation->getOwnerKeyName(),
            ),
            $relation instanceof HasOne        => RelationSearch::hasOne(
                table: $relation->getRelated()->getTable(),
                foreignKey: $relation->getForeignKeyName(),
                localKey: $relation->getLocalKeyName(),
            ),
            $relation instanceof HasMany       => RelationSearch::hasMany(
                table: $relation->getRelated()->getTable(),
                foreignKey: $relation->getForeignKeyName(),
                localKey: $relation->getLocalKeyName(),
            ),
            $relation instanceof BelongsToMany => RelationSearch::belongsToMany(
                table: $relation->getRelated()->getTable(),
                pivot: $relation->getTable(),
                foreignPivotKey: $relation->getForeignPivotKeyName(),
                relatedPivotKey: $relation->getRelatedPivotKeyName(),
                parentKey: $relation->getParentKeyName(),
                relatedKey: $relation->getRelatedKeyName(),
            ),
            default                            => null,
        };
    }
}
