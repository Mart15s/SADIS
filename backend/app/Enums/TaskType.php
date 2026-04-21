<?php

namespace App\Enums;

enum TaskType: string
{
    case Buy = 'buy';
    case Fertilize = 'fertilize';
    case Harvest = 'harvest';
    case Planting = 'planting';
    case Rest = 'rest';
    case Spray = 'spray';
    case Transplant = 'transplant';
    case Watering = 'watering';

    public static function normalize(string|null $value): string
    {
        return match ($value) {
            self::Buy->value,
            self::Fertilize->value,
            self::Harvest->value,
            self::Planting->value,
            self::Rest->value,
            self::Spray->value,
            self::Transplant->value,
            self::Watering->value => $value,
            'material_acquisition' => self::Buy->value,
            'pest_check' => self::Spray->value,
            'weather_protection' => self::Rest->value,
            default => self::Rest->value,
        };
    }
}
