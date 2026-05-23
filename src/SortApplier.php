<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;

class SortApplier implements QueryApplier
{
    /**
     * @param  array<string, \Closure>  $customSorts
     */
    public function __construct(protected array $customSorts = []) {}

    public function apply(Builder $builder, DatatableRequest $request): void
    {
        if (! $request->hasSorting()) {
            return;
        }

        if (isset($this->customSorts[$request->sortBy])) {
            ($this->customSorts[$request->sortBy])($builder, $request->sortOrder);

            return;
        }

        $sortField = $request->sortBy;
        $sortDirection = $request->sortOrder;

        if (str_contains($sortField, '.')) {
            $parts = explode('.', $sortField);
            $column = array_pop($parts);

            if ($builder instanceof EloquentBuilder || $builder instanceof Relation) {
                $model = $builder->getModel();
                $currentModel = $model;
                $currentTable = $model->getTable();
                $relatedAlias = '';

                foreach ($parts as $part) {
                    if (! method_exists($currentModel, $part)) {
                        $builder->orderBy($sortField, $sortDirection);

                        return;
                    }

                    $relation = $currentModel->$part();
                    if (! ($relation instanceof BelongsTo)) {
                        $builder->orderBy($sortField, $sortDirection);

                        return;
                    }

                    $relatedModel = $relation->getRelated();
                    $relatedTable = $relatedModel->getTable();
                    $relatedAlias = $part;

                    $builder->leftJoin(
                        "{$relatedTable} as {$relatedAlias}",
                        "{$relatedAlias}.{$relatedModel->getKeyName()}",
                        '=',
                        "{$currentTable}.{$relation->getForeignKeyName()}"
                    );

                    $currentModel = $relatedModel;
                    $currentTable = $relatedAlias;
                }

                $builder->orderBy("{$relatedAlias}.{$column}", $sortDirection);

                if (empty($builder->getQuery()->columns)) {
                    $builder->select("{$model->getTable()}.*");
                }

                return;
            }
        }

        $builder->orderBy($sortField, $sortDirection);
    }
}
