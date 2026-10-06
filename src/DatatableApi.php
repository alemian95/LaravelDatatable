<?php

declare(strict_types=1);

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use AleMian95\Datatable\Contracts\RelationSearchResolver;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\Search\RelationSearch;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;
use JsonSerializable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public entry point; see docs/adr/0002-public-api-boundary.md.
 */
final class DatatableApi implements JsonSerializable, Responsable
{
    private Builder $builder;

    private DatatableRequest $request;

    /** @var array<string, \Closure> */
    private array $filters = [];

    /** @var array<int, \Closure> */
    private array $legacyFilters = [];

    /** @var array<string, \Closure> */
    private array $customSorts = [];

    private ?\Closure $customSearch = null;

    /** @var array<int, string>|null */
    private ?array $apiDeclaredSearchColumns = null;

    /** @var array<string, RelationSearch> */
    private array $relationSearchMap = [];

    /** @var array<int, string>|null */
    private ?array $apiDeclaredSortColumns = null;

    /** @var class-string<JsonResource>|null */
    private ?string $resourceClass = null;

    /**
     * @internal Use DatatableApi::for(). The argument-less form is deprecated.
     */
    public function __construct(?Builder $query = null, ?Request $request = null)
    {
        if ($query === null) {
            trigger_error(
                'new DatatableApi() + fromQuery() is deprecated and will be removed in 1.0; use DatatableApi::for($query).',
                E_USER_DEPRECATED,
            );
        } else {
            $this->builder = $query;
        }

        $this->request = DatatableRequest::fromRequest($request ?? request());
    }

    /**
     * Entry point. Without an explicit request, the current one is read now.
     */
    public static function for(Builder $query, ?Request $request = null): self
    {
        return new self($query, $request);
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
     * withCustomSorts() are always allowed regardless of this list. Without it,
     * only withCustomSorts() keys are sortable.
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
     * @deprecated Use DatatableApi::for($query). Removed in 1.0.
     *
     * @return $this
     */
    public function fromQuery(Builder $query): self
    {
        $this->builder = $query;

        return $this;
    }

    /**
     * Declare the client filters this endpoint accepts, keyed by the
     * filter[<key>] name. A closure runs only when its key is present and
     * receives the parsed value: a string, or ['from' => ?string, 'to' => ?string].
     * Replaces any previous declaration.
     *
     * @param  array<string, \Closure>  $filters
     * @return $this
     */
    public function withFilters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * @deprecated Use withFilters() for client filters; apply fixed constraints
     *             to the query passed to DatatableApi::for(). Removed in 1.0.
     *
     * @param  array<\Closure>  $filters
     * @return $this
     */
    public function withCustomFilters(array $filters): self
    {
        trigger_error(
            'DatatableApi::withCustomFilters() is deprecated and will be removed in 1.0; use withFilters() for client filters and constrain the query passed to DatatableApi::for() for fixed ones.',
            E_USER_DEPRECATED,
        );

        $this->legacyFilters = array_values($filters);

        return $this;
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     * @return $this
     */
    public function returnResource(string $resourceClass): self
    {
        $this->resourceClass = $resourceClass;

        return $this;
    }

    /**
     * Runs the query on a clone of the builder, so calling it again (or
     * serializing twice) never applies search and sort twice.
     */
    public function toPaginator(): LengthAwarePaginator|ResourceCollection
    {
        $builder = clone $this->builder;

        foreach ($this->appliers() as $applier) {
            $applier->apply($builder, $this->request);
        }

        if (config('laraveldatatable.debug.log_sql', false)) {
            Log::info($builder->toRawSql());
        }

        $paginator = $builder->paginate($this->request->perPage, ['*'], 'page', $this->request->page);

        return $this->resourceClass === null ? $paginator : $this->resourceClass::collection($paginator);
    }

    public function toResponse($request): Response
    {
        $result = $this->toPaginator();

        return $result instanceof ResourceCollection
            ? $result->toResponse($request)
            : new JsonResponse($result);
    }

    public function jsonSerialize(): mixed
    {
        $result = $this->toPaginator();

        // A ResourceCollection serializes to its bare data list; use the same
        // {data, links, meta} envelope that toResponse() sends.
        return $result instanceof ResourceCollection
            ? $result->response()->getData(true)
            : $result;
    }

    /**
     * @return array<int, QueryApplier>
     */
    private function appliers(): array
    {
        return [
            new SearchApplier(
                app(SearchColumnResolver::class),
                $this->customSearch,
                $this->apiDeclaredSearchColumns,
                app(RelationSearchResolver::class),
                $this->relationSearchMap,
            ),
            new SortApplier($this->customSorts, $this->apiDeclaredSortColumns),
            new FilterApplier($this->filters, $this->legacyFilters),
            new KeyTiebreakerApplier,
        ];
    }
}
