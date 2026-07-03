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
        // Force to string|null: array inputs (?search[]=a) would otherwise trip
        // the typed properties with a TypeError before any query runs.
        $search = $request->input('search');
        $this->search = is_string($search) ? $search : null;

        $this->searchColumns = array_filter(explode(',', $request->string('search_columns', '')->toString()));

        $sortBy = $request->input('sort_by');
        $this->sortBy = is_string($sortBy) ? $sortBy : null;

        // Whitelist the direction: an unvalidated value reaches orderBy() (throws
        // on anything but asc/desc) and is handed to custom-sort closures that
        // interpolate it into orderByRaw() — a SQL injection surface otherwise.
        $sortOrder = $request->input('sort_order', 'asc');
        $sortOrder = is_string($sortOrder) ? strtolower($sortOrder) : 'asc';
        $this->sortOrder = in_array($sortOrder, ['asc', 'desc'], true) ? $sortOrder : 'asc';

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
