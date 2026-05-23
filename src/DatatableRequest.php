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
        $this->perPage = $request->integer('per_page', config('datatable.default.per_page', 15));
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
