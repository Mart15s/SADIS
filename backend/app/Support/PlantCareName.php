<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PlantCareName
{
    public static function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();

        return $value !== '' ? $value : null;
    }

    /**
     * @param  iterable<mixed>  $values
     * @return array<int, string>
     */
    public static function normalizedList(iterable $values): array
    {
        return Collection::make($values)
            ->flatMap(function (mixed $value): array {
                if (is_array($value)) {
                    return $value;
                }

                return [$value];
            })
            ->map(fn (mixed $value) => is_scalar($value) ? self::normalize((string) $value) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
