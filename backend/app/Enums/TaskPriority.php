<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function normalize(?string $value): string
    {
        return match ($value) {
            self::Low->value,
            self::Medium->value,
            self::High->value => $value,
            default => self::Medium->value,
        };
    }
}
