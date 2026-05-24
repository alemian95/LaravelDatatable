<?php

namespace AleMian95\Datatable\Search\Sources;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

class AutoDiscoveryColumnSource
{
    private const SEARCHABLE_TYPES = ['string', 'text', 'char', 'varchar', 'tinytext', 'mediumtext', 'longtext', 'uuid', 'guid'];

    /**
     * @param  array<int, string>  $blacklist  Column names or wildcard patterns (case-insensitive).
     */
    public function __construct(private array $blacklist) {}

    /**
     * @return array<int, string>
     */
    public function columns(Builder $builder): array
    {
        if ($builder instanceof EloquentBuilder || $builder instanceof Relation) {
            $model = $builder->getModel();
            $columns = $this->filterColumns($model->getTable(), Schema::getColumnListing($model->getTable()));

            if ($builder instanceof EloquentBuilder) {
                foreach (array_keys($builder->getEagerLoads()) as $relationName) {
                    $columns = array_merge($columns, $this->relationColumns($model, $relationName));
                }
            }

            return array_values($columns);
        }

        if ($builder instanceof QueryBuilder) {
            $table = $builder->from;

            if (! is_string($table)) {
                return [];
            }

            return array_values($this->filterColumns($table, Schema::getColumnListing($table)));
        }

        return [];
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function filterColumns(string $table, array $columns): array
    {
        return array_filter($columns, function (string $column) use ($table): bool {
            return $this->isSearchableType($table, $column) && ! $this->isBlacklisted($column);
        });
    }

    private function isSearchableType(string $table, string $column): bool
    {
        $type = strtolower(Schema::getColumnType($table, $column));

        return in_array($type, self::SEARCHABLE_TYPES, true);
    }

    private function isBlacklisted(string $column): bool
    {
        $column = strtolower($column);

        foreach ($this->blacklist as $pattern) {
            $pattern = strtolower($pattern);

            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';
                if (preg_match($regex, $column) === 1) {
                    return true;
                }
            } elseif ($pattern === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function relationColumns(Model $model, string $relationName): array
    {
        $parts = explode('.', $relationName);
        $currentModel = $model;

        foreach ($parts as $part) {
            if (! method_exists($currentModel, $part)) {
                return [];
            }

            $relation = $currentModel->$part();

            if (! ($relation instanceof Relation)) {
                return [];
            }

            $currentModel = $relation->getRelated();
        }

        $relatedTable = $currentModel->getTable();
        $rawColumns = Schema::getColumnListing($relatedTable);
        $filtered = $this->filterColumns($relatedTable, $rawColumns);

        return array_values(array_map(
            fn (string $column): string => "{$relationName}.{$column}",
            $filtered,
        ));
    }
}
