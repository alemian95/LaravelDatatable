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
                $entry->apply($query, $baseTable, $term);
            }
        });
    }

    /**
     * @param  array<int, string>  $columns
     * @return array{flat: array<int, string>, dotted: array<int, DottedEntry>}
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

            // Multi-hop on Eloquent without an explicit declaration: route to the
            // legacy orWhereHas path. This preserves backward compatibility for
            // a.b.c style searches that were previously handled inline.
            if (
                count($segments) > 2
                && ! isset($this->relationSearchMap[$relationKey])
                && $builder instanceof EloquentBuilder
            ) {
                $dotted[] = new LegacyHasDottedEntry($col);

                continue;
            }

            $spec = $this->relationResolver?->resolve($builder, $relationKey, $this->relationSearchMap);

            if ($spec === null) {
                $dropped[] = $col;

                continue;
            }

            $remoteColumn = implode('.', array_slice($segments, 1));
            $dotted[] = new SpecDottedEntry($spec, $remoteColumn);
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

/**
 * Internal contract: one resolved entry for a dot-notation search column.
 * Two implementations: SpecDottedEntry (RelationSearch-backed),
 * LegacyHasDottedEntry (multi-hop Eloquent `orWhereHas` fallback).
 */
interface DottedEntry
{
    public function apply(Builder $query, string $baseTable, string $term): void;
}

final class SpecDottedEntry implements DottedEntry
{
    public function __construct(
        private readonly RelationSearch $spec,
        private readonly string $remoteColumn,
    ) {}

    public function apply(Builder $query, string $baseTable, string $term): void
    {
        $this->spec->apply($query, $baseTable, $this->remoteColumn, $term);
    }
}

final class LegacyHasDottedEntry implements DottedEntry
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function apply(Builder $query, string $baseTable, string $term): void
    {
        $segments = explode('.', $this->path);
        $column = array_pop($segments);
        $relationPath = implode('.', $segments);

        // Safe by construction: this entry is only created when the builder
        // is an EloquentBuilder, which is the only type that exposes
        // orWhereHas. The closure parameter $query passed into apply() runs
        // inside a where() group on the same builder, preserving the
        // Eloquent type. A runtime instanceof narrows for static analysis.
        if (! $query instanceof EloquentBuilder) {
            return;
        }

        $query->orWhereHas($relationPath, fn (EloquentBuilder $q) => $q->whereLike($column, "%{$term}%"));
    }
}
