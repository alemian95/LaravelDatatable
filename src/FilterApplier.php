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

        $dropped = $request->malformedFilters;

        foreach ($request->filters as $key => $value) {
            if (isset($this->filters[$key])) {
                ($this->filters[$key])($builder, $value);
            } else {
                $dropped[] = $key;
            }
        }

        // One line per request, however many keys a client sends. Legacy
        // closures read filter[...] from the request themselves, so a key we
        // cannot apply is not necessarily unhandled while they exist.
        if ($dropped !== [] && $this->legacyFilters === []) {
            sort($dropped);
            Log::warning(sprintf(
                'FilterApplier dropped filters [%s]: not declared via DatatableApi::withFilters(), or not a non-empty string / {from, to} value.',
                implode(', ', $dropped),
            ));
        }
    }
}
