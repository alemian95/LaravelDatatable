<?php

use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

const SKILL_TARGETS = ['.claude/skills/laraveldatatable/SKILL.md', '.agents/skills/laraveldatatable/SKILL.md'];

function skillPublished(): bool
{
    return collect(SKILL_TARGETS)->every(fn (string $path) => File::exists(base_path($path)));
}

beforeEach(function (): void {
    Process::fake();
    File::put(base_path('package.json'), json_encode(['dependencies' => ['react' => '^18', 'react-dom' => '^18', 'tailwindcss' => '^3']]));
});

afterEach(function (): void {
    File::deleteDirectory(base_path('.claude'));
    File::deleteDirectory(base_path('.agents'));
    File::delete(base_path('package.json'));
    File::delete(config_path('laraveldatatable.php'));
    InstalledVersions::reload(null);
});

it('publishes the Agent Skill to both agent folders', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'laraveldatatable-skill'])->assertSuccessful();

    expect(skillPublished())->toBeTrue()
        ->and(File::get(base_path(SKILL_TARGETS[0])))
        ->toBe(File::get(__DIR__.'/../resources/boost/skills/laraveldatatable/SKILL.md'));
});

it('copies the Agent Skill on install when confirmed, overwriting a stale copy', function (): void {
    File::ensureDirectoryExists(dirname(base_path(SKILL_TARGETS[0])));
    File::put(base_path(SKILL_TARGETS[0]), 'stale');

    $this->artisan('datatable:install')
        ->expectsConfirmation('Copy the Agent Skill for coding agents into .claude/skills and .agents/skills?', 'yes')
        ->assertSuccessful();

    expect(skillPublished())->toBeTrue()
        ->and(File::get(base_path(SKILL_TARGETS[0])))->not->toBe('stale');
});

it('skips the Agent Skill on install when declined', function (): void {
    $this->artisan('datatable:install')
        ->expectsConfirmation('Copy the Agent Skill for coding agents into .claude/skills and .agents/skills?', 'no')
        ->assertSuccessful();

    expect(File::exists(base_path(SKILL_TARGETS[0])))->toBeFalse();
});

it('skips the Agent Skill on install without interaction', function (): void {
    $this->artisan('datatable:install', ['--no-interaction' => true])->assertSuccessful();

    expect(File::exists(base_path(SKILL_TARGETS[0])))->toBeFalse();
});

it('points to Boost instead of copying when Boost is installed', function (): void {
    InstalledVersions::reload([
        'root' => ['name' => 'app/app', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project', 'install_path' => base_path(), 'aliases' => [], 'dev' => true],
        'versions' => ['laravel/boost' => ['pretty_version' => '2.10.3', 'version' => '2.10.3.0', 'reference' => null, 'type' => 'library', 'install_path' => base_path(), 'aliases' => [], 'dev_requirement' => true]],
    ]);

    $this->artisan('datatable:install')
        ->expectsOutputToContain('boost:install')
        ->assertSuccessful();

    expect(File::exists(base_path(SKILL_TARGETS[0])))->toBeFalse();
});
