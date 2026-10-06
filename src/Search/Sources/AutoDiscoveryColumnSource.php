<?php

declare(strict_types=1);

namespace AleMian95\Datatable\Search\Sources;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @internal Not covered by semver; see docs/adr/0002-public-api-boundary.md.
 */
class AutoDiscoveryColumnSource
{
    private const SEARCHABLE_TYPES = ['string', 'text', 'char', 'varchar', 'tinytext', 'mediumtext', 'longtext', 'uuid', 'guid'];

    /**
     * @param  array<int, string>  $blacklist  Column names or wildcard patterns (case-insensitive).
     */
    public function __construct(private array $blacklist) {}

    /**
     * Searchable columns per connection and table. The source lives as long as
     * the scoped resolver (one HTTP request or job), so the schema is read once
     * per table; the database name is part of the key for tenancy setups that
     * repoint a connection mid-process.
     *
     * @var array<string, array<int, string>>
     */
    private array $searchableColumnsByTable = [];

    /**
     * @return array<int, string>
     */
    public function columns(Builder $builder): array
    {
        if ($builder instanceof EloquentBuilder || $builder instanceof Relation) {
            $model = $builder->getModel();
            $columns = $this->searchableColumns($model->getConnection(), $model->getTable());

            if ($builder instanceof EloquentBuilder) {
                foreach (array_keys($builder->getEagerLoads()) as $relationName) {
                    $columns = array_merge($columns, $this->relationColumns($model, $relationName));
                }
            }

            return array_values($columns);
        }

        if ($builder instanceof QueryBuilder) {
            $table = $builder->from;
            $connection = $builder->getConnection();

            if (! is_string($table) || ! $connection instanceof Connection) {
                return [];
            }

            return $this->searchableColumns($connection, $table);
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function searchableColumns(Connection $connection, string $table): array
    {
        $key = $connection->getName().'|'.$connection->getDatabaseName().'|'.$table;

        if (isset($this->searchableColumnsByTable[$key])) {
            return $this->searchableColumnsByTable[$key];
        }

        // One Schema::getColumns() call returns names and types together,
        // instead of a type lookup per column.
        $columns = [];

        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            if (in_array(strtolower($column['type_name']), self::SEARCHABLE_TYPES, true)
                && ! $this->isBlacklisted($column['name'])) {
                $columns[] = $column['name'];
            }
        }

        return $this->searchableColumnsByTable[$key] = $columns;
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
        $cleanParts = [];

        foreach ($parts as $part) {
            // Defensive: stock Laravel's getEagerLoads() returns clean relation
            // names, but unusual constraint strings or future versions could
            // emit "relation as alias" keys. Strip the suffix from each segment
            // so it's used neither to resolve the method nor to prefix the
            // emitted columns — the output is always clean dot-notation that
            // SearchApplier can pass to orWhereHas() (which resolves the
            // relation via the method name, ignoring any eager-load alias).
            $methodName = explode(' as ', $part, 2)[0];
            $cleanParts[] = $methodName;

            if (! method_exists($currentModel, $methodName)) {
                return [];
            }

            $relation = $currentModel->$methodName();

            if (! ($relation instanceof Relation)) {
                return [];
            }

            $currentModel = $relation->getRelated();
        }

        $cleanRelationName = implode('.', $cleanParts);

        $relatedTable = $currentModel->getTable();
        $filtered = $this->searchableColumns($currentModel->getConnection(), $relatedTable);

        return array_values(array_map(
            fn (string $column): string => "{$cleanRelationName}.{$column}",
            $filtered,
        ));
    }
}
