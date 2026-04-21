<?php

namespace App\Enums;

enum AnalysisType: string
{
    case Planning = 'planning';
    case PlantCondition = 'plant_condition';
    case Harvest = 'harvest';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases()
        );
    }
}
