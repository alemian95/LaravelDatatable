<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
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

        $sortField = $request->sortBy;
        if ($sortField === null) {
            return;
        }

        if (isset($this->customSorts[$sortField])) {
            ($this->customSorts[$sortField])($builder, $request->sortOrder);

            return;
        }

        // A declared whitelist is authoritative: a sort_by outside it (and not a
        // custom sort key, handled above) is dropped with a warning rather than
        // reaching the database. Null means enforcement is disabled (legacy).
        if ($this->sortableColumns !== null && ! in_array($sortField, $this->sortableColumns, true)) {
            Log::warning(sprintf(
                'SortApplier dropped sort_by [%s]: not in the whitelist declared via DatatableApi::withSortableColumns().',
                $sortField,
            ));

            return;
        }

        if (str_contains($sortField, '.')) {
            $this->applyRelationSort($builder, $sortField, $request->sortOrder);

            return;
        }

        $builder->orderBy($sortField, $request->sortOrder);
    }

    private function applyRelationSort(Builder $builder, string $sortField, string $sortDirection): void
    {
        // Dot-notation sort requires an explicit whitelist. Without one we would
        // have to invoke a client-named method on the model to discover the
        // relation — a method like save()/delete() would run as a side effect of
        // a read query. Only proceed when the dev has vouched for the path.
        if ($this->sortableColumns === null) {
            Log::warning(sprintf(
                'SortApplier dropped sort_by [%s]: dot-notation sort requires an explicit whitelist via DatatableApi::withSortableColumns().',
                $sortField,
            ));

            return;
        }

        if (! ($builder instanceof EloquentBuilder || $builder instanceof Relation)) {
            Log::warning(sprintf(
                'SortApplier dropped sort_by [%s]: dot-notation sort is only supported on Eloquent builders.',
                $sortField,
            ));

            return;
        }

        $parts = explode('.', $sortField);
        $column = array_pop($parts);

        $model = $builder->getModel();
        $currentModel = $model;
        $currentTable = $model->getTable();
        $relatedAlias = '';

        foreach ($parts as $part) {
            $relation = $this->resolveBelongsTo($currentModel, $part);

            if ($relation === null) {
                Log::warning(sprintf(
                    'SortApplier dropped sort_by [%s]: segment [%s] is not a BelongsTo relation.',
                    $sortField,
                    $part,
                ));

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
    }

    /**
     * Resolve a segment to a BelongsTo relation, or null. Safe by construction:
     * only reached for whitelisted, dev-declared paths, and mirrors the search
     * side (try/catch + instanceof) instead of trusting method_exists alone.
     */
    private function resolveBelongsTo(Model $model, string $name): ?BelongsTo
    {
        if (! method_exists($model, $name)) {
            return null;
        }

        try {
            $relation = $model->{$name}();
        } catch (\Throwable) {
            return null;
        }

        return $relation instanceof BelongsTo ? $relation : null;
    }
}
