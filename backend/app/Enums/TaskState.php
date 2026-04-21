<?php

namespace App\Enums;

enum TaskState: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public static function normalize(string|null $value): string
    {
        return match ($value) {
            'cancelled' => self::Canceled->value,
            self::Pending->value,
            self::Completed->value,
            self::Canceled->value => $value,
            default => self::Pending->value,
        };
    }
}
