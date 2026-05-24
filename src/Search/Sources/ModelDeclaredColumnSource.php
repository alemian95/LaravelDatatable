<?php

namespace AleMian95\Datatable\Search\Sources;

use AleMian95\Datatable\Contracts\HasSearchableColumns;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ModelDeclaredColumnSource
{
    /**
     * Returns the model's declared whitelist, or null if the builder is not
     * Eloquent or the model does not implement HasSearchableColumns. An empty
     * array returned by getSearchableColumns() is treated as a deliberate
     * "block the search" signal and is propagated as such.
     *
     * @return array<int, string>|null
     */
    public function columns(Builder $builder): ?array
    {
        if (! ($builder instanceof EloquentBuilder || $builder instanceof Relation)) {
            return null;
        }

        $model = $builder->getModel();

        if (! $model instanceof HasSearchableColumns) {
            return null;
        }

        return $model->getSearchableColumns();
    }
}
