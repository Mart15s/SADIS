<?php

namespace App\Support;

use Closure;

final class NormalizedGeometry
{
    private const MIN_POINT_COUNT = 3;

    public static function validationRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null) {
                return;
            }

            if (! is_array($value)) {
                $fail('The '.$attribute.' field must be an object.');

                return;
            }

            $points = $value['points'] ?? null;

            if (! is_array($points) || count($points) < self::MIN_POINT_COUNT) {
                $fail('The '.$attribute.' field must contain at least 3 points.');

                return;
            }

            foreach ($points as $index => $point) {
                if (! is_array($point)) {
                    $fail('Each '.$attribute.' point must be an object.');

                    return;
                }

                $x = $point['x'] ?? null;
                $y = $point['y'] ?? null;

                if (! is_numeric($x) || $x < 0 || $x > 1) {
                    $fail('Each '.$attribute.' point x coordinate must be between 0 and 1.');

                    return;
                }

                if (! is_numeric($y) || $y < 0 || $y > 1) {
                    $fail('Each '.$attribute.' point y coordinate must be between 0 and 1.');

                    return;
                }

            }
        };
    }

    public static function isValid(mixed $geometry): bool
    {
        if (! is_array($geometry)) {
            return false;
        }

        $points = $geometry['points'] ?? null;

        if (! is_array($points) || count($points) < self::MIN_POINT_COUNT) {
            return false;
        }

        foreach ($points as $point) {
            if (! is_array($point)) {
                return false;
            }

            $x = $point['x'] ?? null;
            $y = $point['y'] ?? null;

            if (! is_numeric($x) || ! is_numeric($y)) {
                return false;
            }

            if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
                return false;
            }
        }

        return true;
    }
}
