<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

class SortApplier implements QueryApplier
{
    /**
     * @param  array<string, \Closure>  $customSorts
     * @param  array<int, string>|null  $sortableColumns  Authoritative whitelist of sortable columns; null disables enforcement.
     */
    public function __construct(
        protected array $customSorts = [],
        protected ?array $sortableColumns = null,
    ) {}

    public function apply(Builder $builder, DatatableRequest $request): void
    {
        if (! $request->hasSorting()) {
            return;
        }

        if (isset($this->customSorts[$request->sortBy])) {
            ($this->customSorts[$request->sortBy])($builder, $request->sortOrder);

            return;
        }

        // A declared whitelist is authoritative: a sort_by outside it (and not a
        // custom sort key, handled above) is dropped with a warning rather than
        // reaching the database. Null means enforcement is disabled (legacy).
        if ($this->sortableColumns !== null && ! in_array($request->sortBy, $this->sortableColumns, true)) {
            Log::warning(sprintf(
                'SortApplier dropped sort_by [%s]: not in the whitelist declared via DatatableApi::withSortableColumns().',
                $request->sortBy,
            ));

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
