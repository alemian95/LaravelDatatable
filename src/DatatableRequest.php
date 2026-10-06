<?php

namespace AleMian95\Datatable;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DatatableRequest
{
    public readonly ?string $search;

    public readonly array $searchColumns;

    public readonly ?string $sortBy;

    public readonly string $sortOrder;

    public readonly int $perPage;

    public readonly int $page;

    /** @var array<string, string|array{from: ?string, to: ?string}> */
    public readonly array $filters;

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

        // Read here rather than by paginate() from the global request, so an
        // explicit Request passed to DatatableApi::for() drives the page too.
        $this->page = max(1, $request->integer('page', 1));

        $this->filters = self::parseFilters($request->input('filter'));
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request);
    }

    /**
     * @return array<string, string|array{from: ?string, to: ?string}>
     */
    private static function parseFilters(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($raw as $key => $value) {
            // Empty values mean "no filter": the React table omits them, and
            // ConvertEmptyStringsToNull turns "filter[x]=" into null.
            if ($value === null || $value === '') {
                continue;
            }

            $parsed = self::parseFilterValue($value);

            if ($parsed === null) {
                Log::warning(sprintf(
                    'DatatableRequest dropped filter [%s]: expected a non-empty string or {from, to} with at least one bound.',
                    $key,
                ));

                continue;
            }

            $filters[(string) $key] = $parsed;
        }

        return $filters;
    }

    /**
     * @return string|array{from: ?string, to: ?string}|null
     */
    private static function parseFilterValue(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value) || array_diff(array_keys($value), ['from', 'to']) !== []) {
            return null;
        }

        $range = ['from' => null, 'to' => null];

        foreach (['from', 'to'] as $bound) {
            $boundValue = $value[$bound] ?? null;

            if ($boundValue !== null && ! is_string($boundValue)) {
                return null;
            }

            $range[$bound] = $boundValue === '' ? null : $boundValue;
        }

        return $range['from'] === null && $range['to'] === null ? null : $range;
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
