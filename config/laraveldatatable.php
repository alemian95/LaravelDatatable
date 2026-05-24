<?php

// config for AleMian95/Datatable
return [

    'default' => [
        /*
        |--------------------------------------------------------------------------
        | Default page size
        |--------------------------------------------------------------------------
        |
        | Used when the request does not include a "per_page" query parameter.
        |
        */
        'per_page' => 15,
    ],

    'search' => [
        /*
        |--------------------------------------------------------------------------
        | Automatic column discovery
        |--------------------------------------------------------------------------
        |
        | When true, the SearchApplier falls back to Schema introspection if
        | neither DatatableApi::withSearchableColumns() nor the
        | HasSearchableColumns contract on the model provides a whitelist.
        |
        | When false, declaring a whitelist is mandatory: a
        | SearchColumnsNotConfiguredException is thrown otherwise.
        |
        */
        'auto_discover_columns' => true,

        /*
        |--------------------------------------------------------------------------
        | Auto-discovery blacklist
        |--------------------------------------------------------------------------
        |
        | Column names (or wildcard patterns where * matches any sequence)
        | always excluded from auto-discovery. Matching is case-insensitive.
        | Only used when auto_discover_columns is true.
        |
        */
        'auto_discovery_blacklist' => [
            'password',
            'remember_token',
            'api_token',
            '*_token',
            '*_secret',
            '*_hash',
            '*_key',
        ],
    ],

];
