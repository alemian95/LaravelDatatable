<?php

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
        $localKey ??= Str::singular($table) . '_id';

        return new self(function (Builder $query, string $baseTable, string $remoteColumn, string $term)
            use ($table, $localKey, $remoteKey): void {
            $query->orWhereExists(fn (QueryBuilder $sub) =>
                $sub->from($table)
                    ->whereColumn("{$table}.{$remoteKey}", "{$baseTable}.{$localKey}")
                    ->whereLike("{$table}.{$remoteColumn}", "%{$term}%")
            );
        });
    }

    public static function hasOne(
        string $table,
        ?string $foreignKey = null,
        string $localKey = 'id',
    ): self {
        return new self(function (Builder $query, string $baseTable, string $remoteColumn, string $term)
            use ($table, $foreignKey, $localKey): void {
            $foreignKey ??= Str::singular($baseTable) . '_id';

            $query->orWhereExists(fn (QueryBuilder $sub) =>
                $sub->from($table)
                    ->whereColumn("{$table}.{$foreignKey}", "{$baseTable}.{$localKey}")
                    ->whereLike("{$table}.{$remoteColumn}", "%{$term}%")
            );
        });
    }

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
        $relatedPivotKey ??= Str::singular($table) . '_id';

        return new self(function (Builder $query, string $baseTable, string $remoteColumn, string $term)
            use ($table, $pivot, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey): void {
            $foreignPivotKey ??= Str::singular($baseTable) . '_id';

            $query->orWhereExists(fn (QueryBuilder $sub) =>
                $sub->from($table)
                    ->join($pivot, "{$pivot}.{$relatedPivotKey}", '=', "{$table}.{$relatedKey}")
                    ->whereColumn("{$pivot}.{$foreignPivotKey}", "{$baseTable}.{$parentKey}")
                    ->whereLike("{$table}.{$remoteColumn}", "%{$term}%")
            );
        });
    }

    public function apply(Builder $query, string $baseTable, string $remoteColumn, string $term): void
    {
        ($this->applier)($query, $baseTable, $remoteColumn, $term);
    }
}
