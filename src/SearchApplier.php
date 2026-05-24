<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;

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

        $builder->where(function ($query) use ($searchColumns, $request): void {
            foreach ($searchColumns as $field) {
                if (str_contains($field, '.')) {
                    $parts = explode('.', $field);
                    $column = array_pop($parts);
                    $relationPath = implode('.', $parts);

                    if ($query instanceof EloquentBuilder || $query instanceof Relation) {
                        $query->orWhereHas($relationPath, function ($q) use ($column, $request): void {
                            $q->whereLike($column, "%{$request->search}%");
                        });
                    }
                } else {
                    $query->orWhereLike($field, "%{$request->search}%");
                }
            }
        });
    }
}
