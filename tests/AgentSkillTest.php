<?php

use AleMian95\Datatable\DatatableApi;
use AleMian95\Datatable\Search\RelationSearch;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

// The Agent Skill documents the public API (ADR 0002) for the coding agents of
// the package's users: these checks keep it from drifting from the code.

const SKILL_DIR = __DIR__.'/../resources/boost/skills/laraveldatatable';

function skill(): string
{
    return (string) file_get_contents(SKILL_DIR.'/SKILL.md');
}

it('has a frontmatter whose name matches its folder', function (): void {
    expect(preg_match('/\A---\n(.*?)\n---\n/s', skill(), $m))->toBe(1);

    preg_match('/^name:\s*(\S.*)$/m', $m[1], $name);
    preg_match('/^description:\s*(\S.*)$/m', $m[1], $description);

    expect($name[1] ?? null)->toBe(basename(SKILL_DIR))
        ->and($description[1] ?? '')->not->toBe('');
});

it('cites only public classes that exist', function (): void {
    $srcDirs = array_map('basename', glob(__DIR__.'/../src/*', GLOB_ONLYDIR) ?: []);

    preg_match_all('/(?:AleMian95\\\\Datatable\\\\)?((?:[A-Z]\w*\\\\)+[A-Z]\w*)/', skill(), $m);
    $classes = array_unique(array_filter(
        $m[1],
        fn (string $name) => in_array(explode('\\', $name)[0], $srcDirs, true),
    ));

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $short) {
        $class = 'AleMian95\\Datatable\\'.$short;

        expect(class_exists($class) || interface_exists($class) || trait_exists($class))
            ->toBeTrue("{$class} does not exist");
        expect(str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal'))
            ->toBeFalse("{$class} is @internal");
    }
});

it('calls only methods that exist', function (): void {
    // Query builder calls appear inside the closures of the examples.
    $owners = [DatatableApi::class, RelationSearch::class, EloquentBuilder::class, QueryBuilder::class];

    preg_match_all('/(?:->|DatatableApi::|RelationSearch::)(\w+)\(/', skill(), $m);

    foreach (array_unique($m[1]) as $method) {
        expect(collect($owners)->contains(fn (string $class) => method_exists($class, $method)))
            ->toBeTrue("{$method}() exists on none of the documented classes");
    }
});

it('cites only config keys that exist', function (): void {
    $config = require __DIR__.'/../config/laraveldatatable.php';
    $roots = [...array_keys($config), 'laraveldatatable'];

    preg_match_all('/`([a-z_]+(?:\.[a-z_]+)+)`/', skill(), $m);
    $keys = array_filter($m[1], fn (string $key) => in_array(explode('.', $key)[0], $roots, true));

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect(data_get(['laraveldatatable' => $config] + $config, $key))
            ->not->toBeNull("config key {$key} does not exist");
    }
});

it('imports only what the React package exports', function (): void {
    $exports = (string) file_get_contents(__DIR__.'/../react/src/index.ts');

    preg_match_all("/import\s*\{([^}]*)\}\s*from\s*'@alemian95\/laraveldatatable-react'/", skill(), $m);
    $names = collect($m[1])
        ->flatMap(fn (string $list) => explode(',', $list))
        ->map(fn (string $name) => trim(preg_replace('/^type\s+/', '', trim($name))))
        ->filter()
        ->unique();

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect(preg_match('/\b'.preg_quote($name, '/').'\b/', $exports))
            ->toBe(1, "{$name} is not exported by react/src/index.ts");
    }
});

it('documents every public method of DatatableApi', function (): void {
    $methods = collect((new ReflectionClass(DatatableApi::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map->getName()
        ->reject(fn (string $name) => in_array($name, ['toResponse', 'jsonSerialize'], true));

    foreach ($methods as $method) {
        expect(str_contains(skill(), $method.'('))
            ->toBeTrue("DatatableApi::{$method}() is missing from the Agent Skill");
    }
});
