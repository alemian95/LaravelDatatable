<?php

namespace AleMian95\Datatable\Exceptions;

use AleMian95\Datatable\Contracts\HasSearchableColumns;
use LogicException;

class SearchColumnsNotConfiguredException extends LogicException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            'The model [%s] does not implement %s and auto_discover_columns is disabled. '
            .'Either implement the contract on the model, call withSearchableColumns() '
            .'on the DatatableApi instance, or enable auto_discover_columns in '
            .'config/laraveldatatable.php.',
            $modelClass,
            HasSearchableColumns::class,
        ));
    }

    public static function forTable(string $table): self
    {
        return new self(sprintf(
            'No searchable columns declared for table [%s] and auto_discover_columns is disabled. '
            .'Call withSearchableColumns() on the DatatableApi instance or enable '
            .'auto_discover_columns in config/laraveldatatable.php.',
            $table,
        ));
    }
}
