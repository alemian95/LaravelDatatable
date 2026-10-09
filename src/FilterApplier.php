<?php

declare(strict_types=1);

namespace AleMian95\Datatable;

use AleMian95\Datatable\Contracts\QueryApplier;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Log;

/**
 * @internal
 */
final class FilterApplier implements QueryApplier
{
    /**
     * @param  array<string, \Closure>  $filters  Keyed by the filter[<key>] name; receive ($builder, $value).
     */
    public function __construct(private readonly array $filters = []) {}

    public function apply(Builder $builder, DatatableRequest $request): void
    {
        $dropped = $request->malformedFilters;

        foreach ($request->filters as $key => $value) {
            if (isset($this->filters[$key]) && self::accepts($this->filters[$key], $value)) {
                ($this->filters[$key])($builder, $value);
            } else {
                $dropped[] = $key;
            }
        }

        // One line per request, however many keys a client sends.
        if ($dropped !== []) {
            sort($dropped);
            Log::warning(sprintf(
                'FilterApplier dropped filters [%s]: not declared via DatatableApi::withFilters(), or not a non-empty string / {from, to} value matching the closure\'s value type.',
                implode(', ', $dropped),
            ));
        }
    }

    /**
     * A closure typing its value as string or array declares the shape it
     * takes: the other shape is client input it cannot handle (a TypeError,
     * i.e. a 500), so it is dropped like any malformed value.
     *
     * @param  string|array{from: ?string, to: ?string}  $value
     */
    private static function accepts(\Closure $filter, string|array $value): bool
    {
        $type = ((new \ReflectionFunction($filter))->getParameters()[1] ?? null)?->getType();

        if (! $type instanceof \ReflectionNamedType) {
            return true;
        }

        return match ($type->getName()) {
            'string' => is_string($value),
            'array' => is_array($value),
            default => true,
        };
    }
}
