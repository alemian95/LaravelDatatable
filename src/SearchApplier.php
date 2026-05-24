<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

class SearchApplier implements QueryApplier
{
    /**
     * @param  array<int, string>|null  $apiDeclaredColumns
     */
    public function __construct(
        protected SearchColumnResolver $resolver,
        protected ?\Closure $customSearch = null,
        protected ?array $apiDeclaredColumns = null,
    ) {}

    public function apply(Builder $builder, DatatableRequest $request): void
    {
        if (! $request->hasSearch()) {
            return;
        }

        if ($this->customSearch) {
            ($this->customSearch)($builder, $request->search);

            return;
        }

        $searchColumns = $this->resolver->resolve($builder, $request, $this->apiDeclaredColumns);

        if (empty($searchColumns)) {
            return;
        }

        $searchColumns = $this->dropUnsupportedRelationColumns($builder, $searchColumns);

        if (empty($searchColumns)) {
            return;
        }

        $builder->where(function ($query) use ($searchColumns, $request): void {
            foreach ($searchColumns as $field) {
                if (str_contains($field, '.')) {
                    // Safe: dropUnsupportedRelationColumns guarantees we only get
                    // here when the underlying builder is Eloquent/Relation, so
                    // the nested $query is too and supports orWhereHas.
                    $parts = explode('.', $field);
                    $column = array_pop($parts);
                    $relationPath = implode('.', $parts);

                    $query->orWhereHas($relationPath, function ($q) use ($column, $request): void {
                        $q->whereLike($column, "%{$request->search}%");
                    });
                } else {
                    $query->orWhereLike($field, "%{$request->search}%");
                }
            }
        });
    }

    /**
     * Drops dot-notation entries when the builder cannot honor them
     * (raw QueryBuilder doesn't support orWhereHas). Logs a warning naming the
     * ignored columns so a misconfiguration surfaces instead of silently
     * returning zero matches.
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function dropUnsupportedRelationColumns(Builder $builder, array $columns): array
    {
        if ($builder instanceof EloquentBuilder || $builder instanceof Relation) {
            return $columns;
        }

        $dotted = array_values(array_filter($columns, fn (string $c): bool => str_contains($c, '.')));

        if (! empty($dotted)) {
            Log::warning(sprintf(
                'SearchApplier ignored dot-notation columns [%s]: relation-based search is only '.
                'supported on Eloquent builders or Relation instances, not on a raw QueryBuilder. '.
                'Either pass the underlying Eloquent model, override the resolver, or remove the dotted entries.',
                implode(', ', $dotted),
            ));
        }

        return array_values(array_filter($columns, fn (string $c): bool => ! str_contains($c, '.')));
    }
}
