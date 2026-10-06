<?php

declare(strict_types=1);

namespace AleMian95\Datatable\Search;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * One resolved entry for a dot-notation search column. Two implementations:
 * SpecDottedEntry (RelationSearch-backed), LegacyHasDottedEntry (multi-hop
 * Eloquent `orWhereHas` fallback).
 *
 * @internal
 */
interface DottedEntry
{
    public function apply(Builder $query, string $baseTable, string $baseAlias, string $term): void;
}
