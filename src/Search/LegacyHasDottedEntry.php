<?php

declare(strict_types=1);

namespace AleMian95\Datatable\Search;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * @internal
 */
final class LegacyHasDottedEntry implements DottedEntry
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function apply(Builder $query, string $baseTable, string $baseAlias, string $term): void
    {
        $segments = explode('.', $this->path);
        $column = array_pop($segments);
        $relationPath = implode('.', $segments);

        // Safe by construction: this entry is only created when the builder
        // is an EloquentBuilder, which is the only type that exposes
        // orWhereHas. The closure parameter $query passed into apply() runs
        // inside a where() group on the same builder, preserving the
        // Eloquent type. A runtime instanceof narrows for static analysis.
        if (! $query instanceof EloquentBuilder) {
            return;
        }

        $query->orWhereHas($relationPath, fn (EloquentBuilder $q) => ContainsLike::where($q, $column, $term));
    }
}
