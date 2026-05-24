<?php

namespace AleMian95\Datatable\Search\Sources;

use AleMian95\Datatable\Contracts\HasSearchableColumns;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ModelDeclaredColumnSource
{
    /**
     * @return array<int, string>
     */
    public function columns(Builder $builder): array
    {
        if (! ($builder instanceof EloquentBuilder || $builder instanceof Relation)) {
            return [];
        }

        $model = $builder->getModel();

        if (! $model instanceof HasSearchableColumns) {
            return [];
        }

        return $model->getSearchableColumns();
    }
}
