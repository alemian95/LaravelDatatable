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

        /*
        |--------------------------------------------------------------------------
        | Maximum page size
        |--------------------------------------------------------------------------
        |
        | Hard upper bound for the "per_page" query parameter. A larger requested
        | value is clamped down to this cap so a client cannot force an unbounded
        | result set. Set as high as your largest legitimate export requires.
        |
        */
        'max_per_page' => 100,
    ],

    'debug' => [
        /*
        |--------------------------------------------------------------------------
        | Log the generated SQL
        |--------------------------------------------------------------------------
        |
        | When true, the fully-interpolated SQL of each datatable query is written
        | to the log at "info" level. Off by default: the interpolated string can
        | contain the raw search term (potential PII). Enable only while debugging.
        |
        */
        'log_sql' => false,
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
