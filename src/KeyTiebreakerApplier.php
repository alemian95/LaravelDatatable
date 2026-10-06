<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use AleMian95\Datatable\Support\FromClause;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Appends the primary key as the last order so rows that tie on the sorted
 * column keep a stable position across pages. Runs after every other applier.
 *
 * @internal
 */
final class KeyTiebreakerApplier implements QueryApplier
{
    public function apply(Builder $builder, DatatableRequest $request): void
    {
        // Raw queries have no known key; grouped queries could not select it.
        if (! ($builder instanceof EloquentBuilder || $builder instanceof Relation)) {
            return;
        }

        $query = $builder instanceof Relation ? $builder->getBaseQuery() : $builder->getQuery();
        $qualifier = FromClause::qualifier($builder);

        if ($qualifier === '' || ! empty($query->groups)) {
            return;
        }

        $key = $builder->getModel()->getKeyName();
        $qualified = "{$qualifier}.{$key}";

        foreach ($query->orders ?? [] as $order) {
            if (in_array($order['column'] ?? null, [$key, $qualified], true)) {
                return;
            }
        }

        $builder->orderBy($qualified);
    }
}
