<?php

namespace AleMian95\Datatable\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class InstallCommand extends Command
{
    protected $signature = 'datatable:install';

    protected $description = 'Install the React frontend package and publish the config file.';

    private const NPM_PACKAGE = '@alemian95/laraveldatatable-react';

    /** @var array<int, string> */
    private const PEERS = ['react', 'react-dom', 'tailwindcss'];

    public function handle(): int
    {
        $base = base_path();

        if (! file_exists($base.'/package.json')) {
            $this->components->error(
                'No package.json found. This command needs a JavaScript-enabled Laravel app (Vite + React + Tailwind).'
            );

            return self::FAILURE;
        }

        [$manager, $args] = $this->detectPackageManager($base);

        $this->components->info(sprintf('Installing %s with %s...', self::NPM_PACKAGE, $manager));

        $result = Process::path($base)->run([$manager, ...$args, self::NPM_PACKAGE]);

        if (! $result->successful()) {
            $this->components->error("Failed to install the npm package:\n".$result->errorOutput());

            return self::FAILURE;
        }

        $this->components->info('Publishing the config file...');
        $this->callSilent('vendor:publish', ['--tag' => 'laraveldatatable-config']);

        $this->warnMissingPeers($base);
        $this->printNextSteps();

        return self::SUCCESS;
    }

    /**
     * Detect the JS package manager from the lockfile present in the project,
     * returning the executable and the install subcommand args.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function detectPackageManager(string $base): array
    {
        return match (true) {
            file_exists($base.'/bun.lockb') => ['bun', ['add']],
            file_exists($base.'/pnpm-lock.yaml') => ['pnpm', ['add']],
            file_exists($base.'/yarn.lock') => ['yarn', ['add']],
            default => ['npm', ['install']],
        };
    }

    private function warnMissingPeers(string $base): void
    {
        /** @var array<string, mixed> $pkg */
        $pkg = json_decode((string) file_get_contents($base.'/package.json'), true) ?: [];

        $declared = array_merge(
            array_keys((array) ($pkg['dependencies'] ?? [])),
            array_keys((array) ($pkg['devDependencies'] ?? [])),
        );

        $missing = array_values(array_diff(self::PEERS, $declared));

        if ($missing !== []) {
            $this->components->warn(sprintf(
                'Missing peer dependencies: %s. Install them so the datatable can render.',
                implode(', ', $missing),
            ));
        }
    }

    private function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info('Done. Next steps:');
        $this->line('  1. Add the package to your Tailwind <options=bold>content</>:');
        $this->line('       ./node_modules/'.self::NPM_PACKAGE.'/dist/**/*.js');
        $this->line('  2. Render a table:');
        $this->line('       <fg=gray>import { DatatableProvider, DataTable } from \''.self::NPM_PACKAGE.'\'</>');
        $this->line('  3. See the package README for the full API and the request contract.');
        $this->newLine();
    }
}
