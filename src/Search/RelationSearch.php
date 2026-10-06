<?php

declare(strict_types=1);

namespace AleMian95\Datatable\Search;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

final class RelationSearch
{
    private function __construct(
        private readonly \Closure $applier,
    ) {}

    public static function belongsTo(
        string $table,
        ?string $localKey = null,
        string $remoteKey = 'id',
    ): self {
        $localKey ??= Str::singular($table).'_id';

        return new self(function (Builder $query, string $baseTable, string $baseAlias, string $remoteColumn, string $term) use ($table, $localKey, $remoteKey): void {
            $query->orWhereExists(fn (QueryBuilder $sub) => $sub->from($table)
                ->whereColumn("{$table}.{$remoteKey}", "{$baseAlias}.{$localKey}")
                ->tap(fn (QueryBuilder $q) => ContainsLike::where($q, "{$table}.{$remoteColumn}", $term))
            );
        });
    }

    public static function hasOne(
        string $table,
        ?string $foreignKey = null,
        string $localKey = 'id',
    ): self {
        return new self(function (Builder $query, string $baseTable, string $baseAlias, string $remoteColumn, string $term) use ($table, $foreignKey, $localKey): void {
            $foreignKey ??= Str::singular($baseTable).'_id';

            $query->orWhereExists(fn (QueryBuilder $sub) => $sub->from($table)
                ->whereColumn("{$table}.{$foreignKey}", "{$baseAlias}.{$localKey}")
                ->tap(fn (QueryBuilder $q) => ContainsLike::where($q, "{$table}.{$remoteColumn}", $term))
            );
        });
    }

    /**
     * For search purposes, hasMany collapses to the same EXISTS-style subquery as hasOne —
     * the cardinality of the related rows is irrelevant when the predicate is `EXISTS (...)`.
     * Kept as a distinct factory so calling code reads naturally against the underlying relation type.
     */
    public static function hasMany(
        string $table,
        ?string $foreignKey = null,
        string $localKey = 'id',
    ): self {
        return self::hasOne($table, $foreignKey, $localKey);
    }

    public static function belongsToMany(
        string $table,
        string $pivot,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        string $parentKey = 'id',
        string $relatedKey = 'id',
    ): self {
        $relatedPivotKey ??= Str::singular($table).'_id';

        return new self(function (Builder $query, string $baseTable, string $baseAlias, string $remoteColumn, string $term) use ($table, $pivot, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey): void {
            $foreignPivotKey ??= Str::singular($baseTable).'_id';

            $query->orWhereExists(fn (QueryBuilder $sub) => $sub->from($table)
                ->join($pivot, "{$pivot}.{$relatedPivotKey}", '=', "{$table}.{$relatedKey}")
                ->whereColumn("{$pivot}.{$foreignPivotKey}", "{$baseAlias}.{$parentKey}")
                ->tap(fn (QueryBuilder $q) => ContainsLike::where($q, "{$table}.{$remoteColumn}", $term))
            );
        });
    }

    public static function custom(\Closure $resolver): self
    {
        return new self(fn (Builder $query, string $baseTable, string $baseAlias, string $remoteColumn, string $term) => $resolver($query, $remoteColumn, $term));
    }

    /**
     * @param  string  $baseTable  Real table name of the outer query, used to derive default keys.
     * @param  string|null  $baseAlias  Name the outer query's columns are referenced by
     *                                  ("u" for "users as u"); defaults to $baseTable.
     */
    public function apply(Builder $query, string $baseTable, string $remoteColumn, string $term, ?string $baseAlias = null): void
    {
        ($this->applier)($query, $baseTable, $baseAlias ?? $baseTable, $remoteColumn, $term);
    }
}
