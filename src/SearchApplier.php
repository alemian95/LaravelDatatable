<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

class SearchApplier implements QueryApplier
{
    public function __construct(protected ?\Closure $customSearch = null) {}

    public function apply(Builder $builder, DatatableRequest $request): void
    {
        if (! $request->hasSearch()) {
            return;
        }

        if ($this->customSearch) {
            ($this->customSearch)($builder, $request->search);

            return;
        }

        $searchColumns = $request->searchColumns;

        if (empty($searchColumns)) {
            $searchColumns = $this->resolveSearchColumns($builder);
        }

        if (empty($searchColumns)) {
            return;
        }

        $builder->where(function ($query) use ($searchColumns, $request) {
            foreach ($searchColumns as $field) {
                if (str_contains($field, '.')) {
                    $parts = explode('.', $field);
                    $column = array_pop($parts);
                    $relationPath = implode('.', $parts);

                    if ($query instanceof EloquentBuilder || $query instanceof Relation) {
                        $query->orWhereHas($relationPath, function ($q) use ($column, $request) {
                            $q->whereLike($column, "%{$request->search}%");
                        });
                    }
                } else {
                    $query->orWhereLike($field, "%{$request->search}%");
                }
            }
        });
    }

    protected function resolveSearchColumns(Builder $builder): array
    {
        $columns = [];

        if ($builder instanceof EloquentBuilder || $builder instanceof Relation) {
            $model = $builder->getModel();
            $table = $model->getTable();
            $columns = Schema::getColumnListing($table);

            if ($builder instanceof EloquentBuilder) {
                $eagerLoads = $builder->getEagerLoads();
                foreach ($eagerLoads as $relationName => $constraints) {
                    $columns = array_merge($columns, $this->getRelationColumns($model, $relationName));
                }
            }
        } elseif ($builder instanceof QueryBuilder) {
            $table = $builder->from;
            if (is_string($table)) {
                $columns = Schema::getColumnListing($table);
            }
        }

        return $columns;
    }

    protected function getRelationColumns(Model $model, string $relationName): array
    {
        $parts = explode('.', $relationName);
        $currentModel = $model;
        $columns = [];

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
        $tableColumns = Schema::getColumnListing($relatedTable);

        foreach ($tableColumns as $column) {
            $columns[] = "{$relationName}.{$column}";
        }

        return $columns;
    }
}
