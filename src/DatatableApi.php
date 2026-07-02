<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use AleMian95\Datatable\Contracts\RelationSearchResolver;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\Search\RelationSearch;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use JsonSerializable;

class DatatableApi implements JsonSerializable
{
    protected Builder $builder;

    protected DatatableRequest $request;

    /** @var QueryApplier[] */
    protected array $appliers = [];

    /** @var array<string, \Closure> */
    protected array $customSorts = [];

    protected ?\Closure $customSearch = null;

    /** @var array<int, string>|null */
    protected ?array $apiDeclaredSearchColumns = null;

    /** @var array<string, RelationSearch> */
    protected array $relationSearchMap = [];

    /** @var array<int, string>|null */
    protected ?array $apiDeclaredSortColumns = null;

    protected bool $hasResource = false;

    /**
     * @var class-string
     */
    protected string $resourceClass;

    public function __construct()
    {
        $this->request = DatatableRequest::fromRequest(request());
    }

    /**
     * @return $this
     */
    public function withCustomSearch(\Closure $search): self
    {
        $this->customSearch = $search;

        return $this;
    }

    /**
     * Declare the authoritative whitelist of searchable columns for this
     * DatatableApi instance. Wins over HasSearchableColumns on the model and
     * is the only way to expose searchable columns for raw QueryBuilder
     * queries when auto_discover_columns is disabled.
     *
     * @param  array<int, string>  $columns
     * @return $this
     */
    public function withSearchableColumns(array $columns): self
    {
        $this->apiDeclaredSearchColumns = $columns;

        return $this;
    }

    /**
     * Declare per-relation search specs used when a search_columns entry contains a dot
     * (e.g. 'author.name'). Required for raw QueryBuilder; optional override on Eloquent.
     *
     * @param  array<string, RelationSearch>  $map
     * @return $this
     */
    public function withRelationSearch(array $map): self
    {
        $this->relationSearchMap = $map;

        return $this;
    }

    /**
     * @param  array<string, \Closure>  $sorts
     * @return $this
     */
    public function withCustomSorts(array $sorts): self
    {
        $this->customSorts = $sorts;

        return $this;
    }

    /**
     * Declare the authoritative whitelist of columns the client may sort by via
     * the "sort_by" request parameter (dot-notation entries included, e.g.
     * "author.name"). When set, a "sort_by" outside the whitelist is dropped
     * with a warning instead of hitting the database. Keys declared through
     * withCustomSorts() are always allowed regardless of this list. Leave unset
     * to preserve the legacy behavior of sorting by any client-supplied column.
     *
     * @param  array<int, string>  $columns
     * @return $this
     */
    public function withSortableColumns(array $columns): self
    {
        $this->apiDeclaredSortColumns = $columns;

        return $this;
    }

    /**
     * @return $this
     */
    public function fromQuery(Builder $query): self
    {
        $this->builder = $query;

        return $this;
    }

    /**
     * @param  array<\Closure>  $filters
     * @return $this
     */
    public function withCustomFilters(array $filters): self
    {
        $this->appliers[] = new FilterApplier($filters);

        return $this;
    }

    /**
     * @param  class-string  $resourceClass
     * @return $this
     */
    public function returnResource(string $resourceClass): self
    {
        $this->hasResource = true;
        $this->resourceClass = $resourceClass;

        return $this;
    }

    public function jsonSerialize(): mixed
    {
        $appliers = [
            new SearchApplier(
                app(SearchColumnResolver::class),
                $this->customSearch,
                $this->apiDeclaredSearchColumns,
                app(RelationSearchResolver::class),
                $this->relationSearchMap,
            ),
            new SortApplier($this->customSorts, $this->apiDeclaredSortColumns),
            ...$this->appliers,
        ];

        foreach ($appliers as $applier) {
            $applier->apply($this->builder, $this->request);
        }

        if (config('laraveldatatable.debug.log_sql', false)) {
            Log::info($this->builder->toRawSql());
        }

        $paginator = $this->builder->paginate($this->request->perPage);

        if ($this->hasResource) {
            $resource = $this->resourceClass;

            return $resource::collection($paginator);
        }

        return $paginator;
    }
}
