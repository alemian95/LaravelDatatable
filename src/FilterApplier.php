<?php

declare(strict_types=1);

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Log;

/**
 * @internal
 */
final class FilterApplier implements QueryApplier
{
    /**
     * @param  array<string, \Closure>  $filters  Keyed by the filter[<key>] name; receive ($builder, $value).
     * @param  array<int, \Closure>  $legacyFilters  Deprecated withCustomFilters() closures; receive ($builder).
     */
    public function __construct(
        private readonly array $filters = [],
        private readonly array $legacyFilters = [],
    ) {}

    public function apply(Builder $builder, DatatableRequest $request): void
    {
        foreach ($this->legacyFilters as $filter) {
            $filter($builder);
        }

        foreach ($request->filters as $key => $value) {
            if (isset($this->filters[$key])) {
                ($this->filters[$key])($builder, $value);
            } else {
                $this->warn($key, 'not declared via DatatableApi::withFilters()');
            }
        }

        foreach ($request->malformedFilters as $key) {
            $this->warn($key, 'expected a non-empty string or {from, to} with at least one bound');
        }
    }

    private function warn(string $key, string $reason): void
    {
        // Legacy closures read filter[...] from the request themselves, so a
        // key we cannot apply is not necessarily unhandled while they exist.
        if ($this->legacyFilters === []) {
            Log::warning(sprintf('FilterApplier dropped filter [%s]: %s.', $key, $reason));
        }
    }
}
