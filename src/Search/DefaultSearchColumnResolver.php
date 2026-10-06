<?php

namespace AleMian95\Datatable\Search;

use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\DatatableRequest;
use AleMian95\Datatable\Exceptions\SearchColumnsNotConfiguredException;
use AleMian95\Datatable\Search\Sources\ApiDeclaredColumnSource;
use AleMian95\Datatable\Search\Sources\AutoDiscoveryColumnSource;
use AleMian95\Datatable\Search\Sources\ModelDeclaredColumnSource;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;

/**
 * @internal Not covered by semver; see docs/adr/0002-public-api-boundary.md.
 */
class DefaultSearchColumnResolver implements SearchColumnResolver
{
    public function __construct(
        private ApiDeclaredColumnSource $apiSource,
        private ModelDeclaredColumnSource $modelSource,
        private AutoDiscoveryColumnSource $autoSource,
        private bool $autoDiscoverEnabled,
    ) {}

    public function resolve(
        Builder $builder,
        DatatableRequest $request,
        ?array $apiDeclaredColumns,
    ): array {
        $whitelist = $this->resolveWhitelist($builder, $apiDeclaredColumns);

        if ($whitelist !== null) {
            return $this->intersectWithRequest($whitelist, $request);
        }

        if ($this->autoDiscoverEnabled) {
            $autoColumns = $this->autoSource->columns($builder);

            return $this->intersectWithRequest($autoColumns, $request);
        }

        throw $this->makeException($builder);
    }

    /**
     * @param  array<int, string>|null  $apiDeclaredColumns
     * @return array<int, string>|null Null means: no whitelist source produced anything.
     */
    private function resolveWhitelist(Builder $builder, ?array $apiDeclaredColumns): ?array
    {
        $fromApi = $this->apiSource->columns($apiDeclaredColumns);

        if ($fromApi !== null) {
            return $fromApi;
        }

        return $this->modelSource->columns($builder);
    }

    /**
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    private function intersectWithRequest(array $allowed, DatatableRequest $request): array
    {
        if (empty($request->searchColumns)) {
            return $allowed;
        }

        $dropped = array_diff($request->searchColumns, $allowed);

        if ($dropped !== []) {
            Log::warning(sprintf(
                'Search dropped search_columns [%s]: not searchable for this table.',
                implode(', ', $dropped),
            ));
        }

        return array_values(array_intersect($request->searchColumns, $allowed));
    }

    private function makeException(Builder $builder): SearchColumnsNotConfiguredException
    {
        if ($builder instanceof EloquentBuilder || $builder instanceof Relation) {
            return SearchColumnsNotConfiguredException::forModel(get_class($builder->getModel()));
        }

        if ($builder instanceof QueryBuilder && is_string($builder->from)) {
            return SearchColumnsNotConfiguredException::forTable($builder->from);
        }

        return SearchColumnsNotConfiguredException::forTable('unknown');
    }
}
