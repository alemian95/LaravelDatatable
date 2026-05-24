<?php

namespace AleMian95\Datatable\Concerns;

use Illuminate\Support\Facades\Log;

trait HasSearchableColumns
{
    /**
     * Default convention-based implementation: reads from the $searchable
     * property. Override this method to compute the list dynamically (for
     * example based on the authenticated user's role).
     *
     * Emits a warning log if the property is missing or has the wrong type —
     * a likely sign of a typo (e.g. $searchabel) or a developer who mixed in
     * the trait without realizing it requires a $searchable array. A genuinely
     * empty array ($searchable = []) is treated as an authoritative
     * "block the search" signal and does NOT log a warning.
     *
     * @return array<int, string>
     */
    public function getSearchableColumns(): array
    {
        if (! property_exists($this, 'searchable')) {
            Log::warning(sprintf(
                '[%s] uses the HasSearchableColumns trait but does not define a $searchable property. '.
                'Returning an empty whitelist (which now blocks the search). Did you mean to override '.
                'getSearchableColumns() instead, or is there a typo in the property name?',
                static::class,
            ));

            return [];
        }

        if (! is_array($this->searchable)) {
            Log::warning(sprintf(
                '[%s]::$searchable must be an array, got %s. Returning an empty whitelist '.
                '(which now blocks the search).',
                static::class,
                get_debug_type($this->searchable),
            ));

            return [];
        }

        return $this->searchable;
    }
}
