<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use AleMian95\Datatable\Contracts\RelationSearchResolver;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\Search\RelationSearch;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;

class SearchApplier implements QueryApplier
{
    /**
     * @param  array<int, string>|null  $apiDeclaredColumns
     * @param  array<string, RelationSearch>  $relationSearchMap
     */
    public function __construct(
        protected SearchColumnResolver $resolver,
        protected ?\Closure $customSearch = null,
        protected ?array $apiDeclaredColumns = null,
        protected ?RelationSearchResolver $relationResolver = null,
        protected array $relationSearchMap = [],
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

        $resolved = $this->partitionAndResolve($builder, $searchColumns);

        if (empty($resolved['flat']) && empty($resolved['dotted'])) {
            return;
        }

        $term = $request->search;
        $baseTable = $this->baseTableFor($builder);

        $builder->where(function (Builder $query) use ($resolved, $term, $baseTable): void {
            foreach ($resolved['flat'] as $field) {
                $query->orWhereLike($field, "%{$term}%");
            }

            foreach ($resolved['dotted'] as $entry) {
                $entry['spec']->apply($query, $baseTable, $entry['remoteColumn'], $term);
            }
        });
    }

    /**
     * @param  array<int, string>  $columns
     * @return array{flat: array<int, string>, dotted: array<int, array{spec: RelationSearch, remoteColumn: string}>}
     */
    private function partitionAndResolve(Builder $builder, array $columns): array
    {
        $flat = [];
        $dotted = [];
        $dropped = [];

        foreach ($columns as $col) {
            if (! str_contains($col, '.')) {
                $flat[] = $col;

                continue;
            }

            $segments = explode('.', $col);
            $relationKey = $segments[0];

            $spec = $this->relationResolver?->resolve($builder, $relationKey, $this->relationSearchMap);

            if ($spec === null) {
                $dropped[] = $col;

                continue;
            }

            $remoteColumn = implode('.', array_slice($segments, 1));
            $dotted[] = ['spec' => $spec, 'remoteColumn' => $remoteColumn];
        }

        if (! empty($dropped)) {
            Log::warning(sprintf(
                'SearchApplier dropped dotted columns [%s]: no RelationSearch spec found for the leading segment, '.
                'and the builder is not an Eloquent builder with an auto-discoverable relation method. '.
                'Declare them via DatatableApi::withRelationSearch(...).',
                implode(', ', $dropped),
            ));
        }

        return ['flat' => $flat, 'dotted' => $dotted];
    }

    /**
     * Returns the base table name for use by RelationSearch specs.
     *
     * Returns '' when no base table can be inferred (e.g. raw QueryBuilder
     * whose `from` is a subquery expression rather than a string identifier).
     * Callers MUST guard the empty case before handing the result to a
     * spec — passing '' produces invalid SQL like `"x"."id" = ".".".y"`.
     * Task 10 in the implementation plan adds this guard at the apply() level.
     */
    private function baseTableFor(Builder $builder): string
    {
        if ($builder instanceof EloquentBuilder) {
            return $builder->getModel()->getTable();
        }

        if ($builder instanceof Relation) {
            return $builder->getRelated()->getTable();
        }

        if ($builder instanceof QueryBuilder && is_string($builder->from)) {
            return $builder->from;
        }

        return '';
    }
}
