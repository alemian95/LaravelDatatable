<?php

declare(strict_types=1);

namespace AleMian95\Datatable\Search;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Case-insensitive "column contains term" with the term's LIKE wildcards
 * taken literally. whereLike() has no ESCAPE clause and SQLite has no default
 * escape character, so the clause is written by hand with an explicit one.
 *
 * @internal
 */
final class ContainsLike
{
    private const ESCAPE = '!';

    public static function where(Builder $query, string $column, string $term, string $boolean = 'and'): void
    {
        $pattern = '%'.str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $term,
        ).'%';

        $grammar = $query->getGrammar();
        $wrapped = $grammar->wrap($column);

        // Same operators whereLike() picks for a case-insensitive match.
        $sql = $grammar instanceof PostgresGrammar
            ? "{$wrapped}::text ilike ? escape '".self::ESCAPE."'"
            : "{$wrapped} like ? escape '".self::ESCAPE."'";

        $query->whereRaw($sql, [$pattern], $boolean);
    }

    public static function orWhere(Builder $query, string $column, string $term): void
    {
        self::where($query, $column, $term, 'or');
    }
}
