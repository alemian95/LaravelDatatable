<?php

namespace AleMian95\Datatable;

use AleMian95\Datatable\Commands\DatatableCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DatatableServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laraveldatatable')
            ->hasConfigFile();
        // ->hasViews()
        // ->hasMigration('create_laraveldatatable_table')
        // ->hasCommand(DatatableCommand::class)
    }
}
