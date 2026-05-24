<?php

namespace AleMian95\Datatable\Search\Sources;

class ApiDeclaredColumnSource
{
    /**
     * @param  array<int, string>|null  $apiDeclaredColumns
     * @return array<int, string>
     */
    public function columns(?array $apiDeclaredColumns): array
    {
        return $apiDeclaredColumns ?? [];
    }
}
