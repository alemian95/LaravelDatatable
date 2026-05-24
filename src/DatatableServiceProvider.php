<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\RelationSearchResolver;
use AleMian95\Datatable\Contracts\SearchColumnResolver;
use AleMian95\Datatable\Search\DefaultRelationSearchResolver;
use AleMian95\Datatable\Search\DefaultSearchColumnResolver;
use AleMian95\Datatable\Search\Sources\ApiDeclaredColumnSource;
use AleMian95\Datatable\Search\Sources\AutoDiscoveryColumnSource;
use AleMian95\Datatable\Search\Sources\ModelDeclaredColumnSource;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DatatableServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laraveldatatable')
            ->hasConfigFile();
    }

    public function registeringPackage(): void
    {
        // Scoped instead of singleton: a fresh resolver is built for each HTTP
        // request / queue job, picking up runtime config changes (e.g. a
        // multi-tenant context that swaps laraveldatatable.search.*). The
        // resolver itself is stateless, so the per-request construction cost
        // is negligible.
        $this->app->scoped(SearchColumnResolver::class, function ($app): DefaultSearchColumnResolver {
            $config = $app['config']->get('laraveldatatable.search', []);

            return new DefaultSearchColumnResolver(
                new ApiDeclaredColumnSource,
                new ModelDeclaredColumnSource,
                new AutoDiscoveryColumnSource($config['auto_discovery_blacklist'] ?? []),
                (bool) ($config['auto_discover_columns'] ?? true),
            );
        });

        $this->app->scoped(
            RelationSearchResolver::class,
            fn () => new DefaultRelationSearchResolver,
        );
    }
}
