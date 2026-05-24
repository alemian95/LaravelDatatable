<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\SearchColumnResolver;
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
        $this->app->singleton(SearchColumnResolver::class, function ($app): DefaultSearchColumnResolver {
            $config = $app['config']->get('laraveldatatable.search', []);

            return new DefaultSearchColumnResolver(
                new ApiDeclaredColumnSource(),
                new ModelDeclaredColumnSource(),
                new AutoDiscoveryColumnSource($config['auto_discovery_blacklist'] ?? []),
                (bool) ($config['auto_discover_columns'] ?? true),
            );
        });
    }
}
