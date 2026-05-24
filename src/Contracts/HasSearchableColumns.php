<?php

namespace AleMian95\Datatable\Contracts;

interface HasSearchableColumns
{
    /**
     * Columns authorized for the search of this Model.
     * Supports dot-notation for relations (e.g. "author.name").
     *
     * @return array<int, string>
     */
    public function getSearchableColumns(): array;
}
