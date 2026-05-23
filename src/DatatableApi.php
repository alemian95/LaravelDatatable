<?php

namespace AleMian95\Datatable;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use JsonSerializable;
use AleMian95\Datatable\Contracts\QueryApplier;

class DatatableApi implements JsonSerializable
{
    protected Builder $builder;

    protected DatatableRequest $request;

    /** @var QueryApplier[] */
    protected array $appliers = [];

    /** @var array<string, \Closure> */
    protected array $customSorts = [];

    protected ?\Closure $customSearch = null;

    protected bool $hasResource = false;

    /**
     * @var class-string
     */
    protected string $resourceClass;

    protected bool $hasRelationshipsAutoloading = false;

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
     * @param  array<string, \Closure>  $sorts
     * @return $this
     */
    public function withCustomSorts(array $sorts): self
    {
        $this->customSorts = $sorts;

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
            new SearchApplier($this->customSearch),
            new SortApplier($this->customSorts),
            ...$this->appliers,
        ];

        foreach ($appliers as $applier) {
            $applier->apply($this->builder, $this->request);
        }

        ! app()->isProduction() && Log::info($this->builder->toRawSql());

        $paginator = $this->builder->paginate($this->request->perPage);

        if ($this->hasRelationshipsAutoloading && method_exists($this->builder, 'withRelationshipsAutoloading')) {
            $this->builder->withRelationshipsAutoloading();
        }

        if ($this->hasResource) {
            $resource = $this->resourceClass;

            return $resource::collection($paginator);
        }

        return $paginator;
    }
}
