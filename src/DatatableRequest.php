<?php

namespace AleMian95\Datatable;

use Illuminate\Http\Request;

class DatatableRequest
{
    public readonly ?string $search;

    public readonly array $searchColumns;

    public readonly ?string $sortBy;

    public readonly string $sortOrder;

    public readonly int $perPage;

    public function __construct(Request $request)
    {
        $this->search = $request->input('search');
        $this->searchColumns = array_filter(explode(',', $request->string('search_columns', '')->toString()));
        $this->sortBy = $request->input('sort_by');
        $this->sortOrder = $request->input('sort_order', 'asc');
        // ponytail: clamp to [1, max] so a client cannot request an unbounded
        // page size (DoS). Raise max_per_page in config if a legit caller needs more.
        $perPage = $request->integer('per_page', (int) config('laraveldatatable.default.per_page', 15));
        $maxPerPage = (int) config('laraveldatatable.default.max_per_page', 100);
        $this->perPage = max(1, min($perPage, $maxPerPage));
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request);
    }

    public function hasSearch(): bool
    {
        return ! empty($this->search);
    }

    public function hasSorting(): bool
    {
        return ! empty($this->sortBy);
    }
}
