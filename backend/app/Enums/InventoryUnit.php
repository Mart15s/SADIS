<?php

namespace App\Enums;

enum InventoryUnit: string
{
    case Unit = 'unit';
    case Gram = 'g';
    case Kilogram = 'kg';
    case Milliliter = 'ml';
    case Liter = 'l';
    case Bag = 'bag';
    case Pack = 'pack';
    case CubicMeter = 'm3';

    /**
     * @return array<int, self>
     */
    public static function materialUnits(): array
    {
        return [
            self::Unit,
            self::Gram,
            self::Kilogram,
            self::Milliliter,
            self::Liter,
            self::Bag,
            self::Pack,
            self::CubicMeter,
        ];
    }

    /**
     * @return array<int, self>
     */
    public static function toolUnits(): array
    {
        return [self::Unit];
    }

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'vnt.',
            self::Gram => 'g',
            self::Kilogram => 'kg',
            self::Milliliter => 'ml',
            self::Liter => 'l',
            self::Bag => 'maiš.',
            self::Pack => 'pak.',
            self::CubicMeter => 'm3',
        };
    }
}
