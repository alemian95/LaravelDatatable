<?php

declare(strict_types=1);

namespace AleMian95\Datatable;

use AleMian95\Datatable\Commands\InstallCommand;
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
            ->hasConfigFile()
            ->hasCommand(InstallCommand::class);
    }

    public function registeringPackage(): void
    {
        // Scoped instead of singleton: a fresh resolver is built for each HTTP
        // request / queue job, picking up runtime config changes (e.g. a
        // multi-tenant context that swaps laraveldatatable.search.*). The
        // resolver itself is stateless, so the per-request construction cost
        // is negligible.
        $this->app->scoped(SearchColumnResolver::class, function (): DefaultSearchColumnResolver {
            // Read loosely: published config often holds env() strings or nulls,
            // which the typed config()->array()/boolean() getters reject.
            $blacklist = config('laraveldatatable.search.auto_discovery_blacklist');
            $discover = config('laraveldatatable.search.auto_discover_columns');

            return new DefaultSearchColumnResolver(
                new ApiDeclaredColumnSource,
                new ModelDeclaredColumnSource,
                new AutoDiscoveryColumnSource(is_array($blacklist) ? array_values(array_filter($blacklist, 'is_string')) : []),
                filter_var($discover, FILTER_VALIDATE_BOOL),
            );
        });

        $this->app->scoped(
            RelationSearchResolver::class,
            fn () => new DefaultRelationSearchResolver,
        );
    }
}
