<?php

declare(strict_types=1);

namespace AleMian95\Datatable\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @internal
 */
final class FromClause
{
    /**
     * Name to qualify base-table columns with, read from the from clause the
     * query actually runs (Eloquent and relations included): the alias for
     * "table as alias", the table otherwise, '' when the from clause is not a
     * plain identifier (subquery).
     */
    public static function qualifier(Builder $builder): string
    {
        $from = match (true) {
            $builder instanceof QueryBuilder => $builder->from,
            $builder instanceof EloquentBuilder => $builder->getQuery()->from,
            $builder instanceof Relation => $builder->getBaseQuery()->from,
            default => null,
        };

        if (! is_string($from)) {
            return '';
        }

        $parts = preg_split('/\s+as\s+/i', $from, 2) ?: [$from];

        return $parts[1] ?? $parts[0];
    }
}
