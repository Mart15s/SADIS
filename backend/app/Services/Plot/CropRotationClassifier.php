<?php

namespace App\Services\Plot;

use App\Models\Plant;
use App\Models\PlantCare;
use Illuminate\Support\Str;

class CropRotationClassifier
{
    /**
     * @return array{family: string|null, group: string|null, nutrient_role: string, labels: array<int, string>, has_rotation_data: bool}
     */
    public function profileForPlant(Plant $plant): array
    {
        $care = $this->resolvePlantCare($plant);
        $family = $this->normalizeFamily(
            $plant->catalogPlant?->source_family
                ?? $care?->source_family
                ?? null
        );
        $nameSignal = Str::lower(trim(implode(' ', array_filter([
            $plant->name,
            $plant->catalogPlant?->name,
            $plant->catalogPlant?->canonical_name,
            $care?->plant_name,
            $care?->canonical_name,
            $care?->source_common_name,
            $care?->source_scientific_name,
        ]))));

        $group = $this->groupFromFamily($family)
            ?? $this->groupFromName($nameSignal)
            ?? $this->groupFromPlantType($plant->type?->value ?? $plant->type);

        return [
            'family' => $family,
            'group' => $group,
            'nutrient_role' => $this->nutrientRole($group),
            'labels' => array_values(array_filter(array_unique([$family, $group]))),
            'has_rotation_data' => $family !== null || $group !== null,
        ];
    }

    private function resolvePlantCare(Plant $plant): ?PlantCare
    {
        if ($plant->relationLoaded('catalogPlant') && $plant->catalogPlant?->relationLoaded('plantCare')) {
            return $plant->catalogPlant->plantCare;
        }

        return $plant->effectivePlantCare();
    }

    private function normalizeFamily(?string $family): ?string
    {
        $family = Str::of((string) $family)
            ->lower()
            ->replaceMatches('/\bfamily\b/u', '')
            ->replaceMatches('/[^a-z]/u', '')
            ->trim()
            ->value();

        return $family === '' ? null : Str::studly(Str::lower($family));
    }

    private function groupFromFamily(?string $family): ?string
    {
        return match ($family) {
            'Fabaceae' => 'legume',
            'Apiaceae' => 'root',
            'Amaranthaceae' => 'root',
            'Asteraceae' => 'leafy',
            'Amaryllidaceae' => 'allium',
            'Cucurbitaceae' => 'cucurbit',
            'Solanaceae' => 'solanaceae',
            'Brassicaceae' => 'brassica',
            default => null,
        };
    }

    private function groupFromName(string $nameSignal): ?string
    {
        $rules = [
            'legume' => ['bean', 'beans', 'pea', 'peas', 'pupa', 'pupos', 'zirniai', 'žirniai', 'fabaceae'],
            'root' => ['carrot', 'morka', 'morkos', 'beet', 'burokelis', 'burokėlis', 'radish', 'ridikas', 'apiaceae', 'amaranthaceae'],
            'leafy' => ['lettuce', 'salota', 'salotos', 'spinach', 'špinatai', 'spinatai', 'greens'],
            'allium' => ['onion', 'svogunas', 'svogūnas', 'garlic', 'česnakas', 'cesnakas', 'leek'],
            'cucurbit' => ['cucumber', 'agurkas', 'agurkai', 'squash', 'pumpkin', 'moliugas', 'cucurbitaceae'],
            'solanaceae' => ['tomato', 'pomidoras', 'pepper', 'paprika', 'potato', 'bulve', 'solanaceae'],
            'brassica' => ['cabbage', 'kopustas', 'kopūstas', 'broccoli', 'cauliflower', 'kale', 'brassicaceae'],
        ];

        foreach ($rules as $group => $needles) {
            foreach ($needles as $needle) {
                if (Str::contains($nameSignal, $needle)) {
                    return $group;
                }
            }
        }

        return null;
    }

    private function groupFromPlantType(mixed $plantType): ?string
    {
        return match ((string) $plantType) {
            'legume' => 'legume',
            default => null,
        };
    }

    private function nutrientRole(?string $group): string
    {
        return match ($group) {
            'legume' => 'nitrogen_restoring',
            'leafy', 'cucurbit', 'solanaceae', 'brassica' => 'heavy_feeder',
            'root', 'allium' => 'light_feeder',
            default => 'neutral',
        };
    }
}
