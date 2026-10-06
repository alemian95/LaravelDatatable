<?php

declare(strict_types=1);

namespace AleMian95\Datatable;

use Illuminate\Http\Request;

/**
 * @internal Not covered by semver; see docs/adr/0002-public-api-boundary.md.
 */
class DatatableRequest
{
    public readonly ?string $search;

    /** @var array<int, string> */
    public readonly array $searchColumns;

    public readonly ?string $sortBy;

    /** @var 'asc'|'desc' */
    public readonly string $sortOrder;

    public readonly int $perPage;

    public readonly int $page;

    /** @var array<string, string|array{from: ?string, to: ?string}> */
    public readonly array $filters;

    /**
     * Keys whose value had an unsupported shape; reported by FilterApplier,
     * which knows whether legacy closures may handle them.
     *
     * @var array<int, string>
     */
    public readonly array $malformedFilters;

    public function __construct(Request $request)
    {
        // Force to non-empty string|null: array inputs (?search[]=a) would
        // otherwise trip the typed properties with a TypeError before any query
        // runs. Compared with '' rather than empty(), so "0" is a real term.
        $search = $request->input('search');
        $this->search = is_string($search) && $search !== '' ? $search : null;

        $searchColumns = $request->input('search_columns');
        $this->searchColumns = is_string($searchColumns) ? array_filter(explode(',', $searchColumns)) : [];

        $sortBy = $request->input('sort_by');
        $this->sortBy = is_string($sortBy) && $sortBy !== '' ? $sortBy : null;

        // Whitelist the direction: an unvalidated value reaches orderBy() (throws
        // on anything but asc/desc) and is handed to custom-sort closures that
        // interpolate it into orderByRaw() — a SQL injection surface otherwise.
        $sortOrder = $request->input('sort_order', 'asc');
        $sortOrder = is_string($sortOrder) ? strtolower($sortOrder) : 'asc';
        $this->sortOrder = in_array($sortOrder, ['asc', 'desc'], true) ? $sortOrder : 'asc';

        // ponytail: clamp to [1, max] so a client cannot request an unbounded
        // page size (DoS). Raise max_per_page in config if a legit caller needs more.
        $perPage = self::integer($request, 'per_page', self::configInteger('laraveldatatable.default.per_page', 15));
        $maxPerPage = self::configInteger('laraveldatatable.default.max_per_page', 100);
        $this->perPage = max(1, min($perPage, $maxPerPage));

        // Read here rather than by paginate() from the global request, so an
        // explicit Request passed to DatatableApi::for() drives the page too.
        $this->page = max(1, self::integer($request, 'page', 1));

        [$this->filters, $this->malformedFilters] = self::parseFilters($request->input('filter'));
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request);
    }

    // Config often comes from env(), i.e. strings: accept any numeric value
    // rather than config()->integer(), which throws on "25".
    private static function configInteger(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    // Request::integer() casts an array to 1; anything non-numeric falls back
    // to the default instead.
    private static function integer(Request $request, string $key, int $default): int
    {
        $value = $request->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array{0: array<string, string|array{from: ?string, to: ?string}>, 1: array<int, string>}
     */
    private static function parseFilters(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [[], []];
        }

        $filters = [];
        $malformed = [];

        foreach ($raw as $key => $value) {
            // Empty values mean "no filter": the React table omits them, and
            // ConvertEmptyStringsToNull turns "filter[x]=" into null.
            if ($value === null || $value === '') {
                continue;
            }

            $parsed = self::parseFilterValue($value);

            if ($parsed === null) {
                $malformed[] = (string) $key;

                continue;
            }

            $filters[(string) $key] = $parsed;
        }

        return [$filters, $malformed];
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
}
