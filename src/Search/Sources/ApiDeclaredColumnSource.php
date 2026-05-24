<?php

namespace AleMian95\Datatable\Search\Sources;

class ApiDeclaredColumnSource
{
    /**
     * Returns the authoritative whitelist passed via
     * DatatableApi::withSearchableColumns(), or null if the method was never
     * called. An empty array is an authoritative signal to omit the search
     * clause (no LIKE applied, dataset returned unfiltered by the search
     * term) and is propagated as such, not collapsed to null.
     *
     * @param  array<int, string>|null  $apiDeclaredColumns
     * @return array<int, string>|null
     */
    public function columns(?array $apiDeclaredColumns): ?array
    {
        return $apiDeclaredColumns;
    }
}
