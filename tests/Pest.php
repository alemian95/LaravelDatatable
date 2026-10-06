<?php

use AleMian95\Datatable\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * SQL text in SQLite's dialect, so assertions hold on every driver the suite
 * runs against: MySQL backticks become double quotes, and Postgres'
 * case-insensitive `::text ilike` reads as `like`.
 */
function sql($builder): string
{
    return normalizeSql($builder->toSql());
}

function rawSql($builder): string
{
    return normalizeSql($builder->toRawSql());
}

function normalizeSql(string $sql): string
{
    return str_replace(['`', '::text ilike'], ['"', ' like'], $sql);
}
