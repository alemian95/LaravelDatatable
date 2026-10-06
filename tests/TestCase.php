<?php

namespace AleMian95\Datatable\Tests;

use AleMian95\Datatable\DatatableServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'AleMian95\\Datatable\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            DatatableServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        // DB_CONNECTION=mysql|pgsql runs the suite against a real server (CI
        // services, or local containers); the default is in-memory SQLite.
        $driver = env('DB_CONNECTION', 'sqlite');

        if ($driver === 'sqlite') {
            config()->set('database.default', 'testing');

            return;
        }

        config()->set('database.default', $driver);
        config()->set("database.connections.{$driver}", array_merge(
            config("database.connections.{$driver}"),
            [
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', $driver === 'pgsql' ? 'postgres' : 'root'),
                'password' => env('DB_PASSWORD', 'secret'),
            ],
        ));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
