<?php

declare(strict_types=1);

namespace AleMian95\Datatable\Search;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Case-insensitive "column contains term" with the term's LIKE wildcards
 * taken literally. whereLike() has no ESCAPE clause and SQLite has no default
 * escape character, so the clause is written by hand with an explicit one.
 * The SQL is an Expression built from the grammar at compile time: only the
 * wrapped column name is interpolated, the term is always a binding.
 *
 * @internal
 */
final class ContainsLike implements Expression
{
    private const ESCAPE = '!';

    private function __construct(private readonly string $column) {}

    public static function where(Builder $query, string $column, string $term, string $boolean = 'and'): void
    {
        $pattern = '%'.str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $term,
        ).'%';

        $query->whereRaw(new self($column), [$pattern], $boolean);
    }

    public static function orWhere(Builder $query, string $column, string $term): void
    {
        self::where($query, $column, $term, 'or');
    }

    public function getValue(Grammar $grammar): string
    {
        $wrapped = $grammar->wrap($this->column);

        // Same operators whereLike() picks for a case-insensitive match.
        return $grammar instanceof PostgresGrammar
            ? "{$wrapped}::text ilike ? escape '".self::ESCAPE."'"
            : "{$wrapped} like ? escape '".self::ESCAPE."'";
    }
}
