<?php

namespace AleMian95\Datatable\Concerns;

trait HasSearchableColumns
{
    /**
     * Default convention-based implementation: reads from the $searchable
     * property. Override this method to compute the list dynamically (for
     * example based on the authenticated user's role).
     *
     * @return array<int, string>
     */
    public function getSearchableColumns(): array
    {
        return property_exists($this, 'searchable') && is_array($this->searchable)
            ? $this->searchable
            : [];
    }
}
