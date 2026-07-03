<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function writePackageJson(array $contents = ['dependencies' => ['react' => '^18', 'react-dom' => '^18', 'tailwindcss' => '^3']]): void
{
    File::put(base_path('package.json'), json_encode($contents));
}

afterEach(function (): void {
    foreach (['package.json', 'bun.lockb', 'pnpm-lock.yaml', 'yarn.lock'] as $file) {
        if (File::exists(base_path($file))) {
            File::delete(base_path($file));
        }
    }
});

it('installs the npm package with npm by default and publishes the config', function (): void {
    Process::fake();
    writePackageJson();

    $this->artisan('datatable:install')->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === ['npm', 'install', '@alemian95/laraveldatatable-react']);
});

it('uses the package manager detected from the lockfile', function (): void {
    Process::fake();
    writePackageJson();
    File::put(base_path('pnpm-lock.yaml'), '');

    $this->artisan('datatable:install')->assertSuccessful();

    Process::assertRan(fn ($process) => $process->command === ['pnpm', 'add', '@alemian95/laraveldatatable-react']);
});

it('fails and runs nothing when there is no package.json', function (): void {
    Process::fake();

    $this->artisan('datatable:install')->assertFailed();

    Process::assertNothingRan();
});

it('fails when the npm install command fails', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);
    writePackageJson();

    $this->artisan('datatable:install')->assertFailed();
});

it('warns about missing peer dependencies', function (): void {
    Process::fake();
    writePackageJson(['dependencies' => ['react' => '^18']]);

    $this->artisan('datatable:install')
        ->expectsOutputToContain('react-dom')
        ->assertSuccessful();
});
