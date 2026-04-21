<?php

namespace App\Services;

use App\Enums\ConditionType;
use App\Enums\TaskType;
use App\Models\CatalogPlant;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Support\PlantCareName;
use Illuminate\Support\Collection;

class PlantCareRepairService
{
    private const CARE_COMPLETENESS_FIELDS = [
        'description',
        'conditions',
        'growing_duration_days',
        'flowering_duration_days',
        'germinating_duration_days',
        'mature_duration_days',
        'mature_duration_end_days',
        'mature_end_duration_days',
        'regenerating_duration_days',
        'watering_interval_days',
        'fertilizing_interval_days',
        'pest_check_interval_days',
        'rain_skip_threshold_mm',
        'frost_temp_threshold_c',
        'heat_extra_water_temp_c',
        'wind_protection_kmh',
    ];

    public function __construct(
        private readonly PlantCareService $plantCareService,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function repair(): array
    {
        $catalogPlants = CatalogPlant::query()
            ->with(['plantCare'])
            ->orderBy('id')
            ->get();

        $summary = [
            'catalog_total' => $catalogPlants->count(),
            'catalog_already_correct' => 0,
            'catalog_repaired_or_completed' => 0,
            'catalog_partially_incomplete_after' => 0,
            'plant_total' => 0,
            'plant_catalog_links_repaired' => 0,
        ];

        foreach ($catalogPlants as $catalogPlant) {
            $beforeCareId = $catalogPlant->fk_plant_care_id;
            $beforeCompleteness = $catalogPlant->plantCare ? $this->filledCareFieldCount($catalogPlant->plantCare) : 0;

            $care = $this->repairCatalogPlant($catalogPlant);

            $afterCompleteness = $care ? $this->filledCareFieldCount($care) : 0;

            if ($beforeCareId && $beforeCompleteness >= count(self::CARE_COMPLETENESS_FIELDS)) {
                $summary['catalog_already_correct']++;
            } elseif ($care) {
                $summary['catalog_repaired_or_completed']++;
            }

            if ($afterCompleteness < count(self::CARE_COMPLETENESS_FIELDS)) {
                $summary['catalog_partially_incomplete_after']++;
            }
        }

        $plants = Plant::query()
            ->with(['catalogPlant.plantCare'])
            ->orderBy('id')
            ->get();

        $summary['plant_total'] = $plants->count();

        foreach ($plants as $plant) {
            $beforeCatalogId = $plant->fk_catalog_plant_id;

            $catalogPlant = $this->repairPlantCatalogLink($plant);

            if (! $catalogPlant) {
                continue;
            }

            if ((int) $beforeCatalogId !== (int) $catalogPlant->id) {
                $summary['plant_catalog_links_repaired']++;
            }

            $catalogPlant->loadMissing('plantCare');
            $plant->forceFill([
                'fk_catalog_plant_id' => $catalogPlant->id,
                'name' => $plant->name ?: $catalogPlant->name,
                'type' => $plant->type?->value ?? $plant->type ?? $catalogPlant->plant_type?->value ?? $catalogPlant->plant_type,
                'reusable' => (bool) ($catalogPlant->plantCare?->reusable ?? false),
            ])->save();
        }

        return $summary;
    }

    private function repairCatalogPlant(CatalogPlant $catalogPlant): ?PlantCare
    {
        $canonicalName = $this->canonicalName(
            $catalogPlant->canonical_name,
            $catalogPlant->name
        );

        $linkedCare = $catalogPlant->plantCare;
        $candidateCare = $this->candidateCareProfilesForCatalog($catalogPlant, $canonicalName)
            ->sortByDesc(fn (PlantCare $care) => $this->filledCareFieldCount($care))
            ->first();

        $care = $linkedCare ?? $candidateCare;

        if (! $care) {
            $care = new PlantCare();
        }

        $care->fill($this->mergeCatalogCareAttributes(
            $care,
            $catalogPlant,
            $candidateCare,
            $canonicalName
        ));
        $care->save();

        $catalogPlant->forceFill([
            'canonical_name' => $canonicalName,
            'plant_type' => $catalogPlant->plant_type?->value ?? $catalogPlant->plant_type ?? $care->plant_type?->value ?? $care->plant_type,
            'fk_plant_care_id' => $care->id,
            'description' => $catalogPlant->description ?? $care->description,
            'source_provider' => $catalogPlant->source_provider ?? $care->source_provider,
            'source_quality' => $catalogPlant->source_quality ?? $care->source_quality,
            'source_scientific_name' => $catalogPlant->source_scientific_name ?? $care->source_scientific_name,
            'source_family' => $catalogPlant->source_family ?? $care->source_family,
            'source_image_url' => $catalogPlant->source_image_url ?? $care->source_image_url,
        ])->save();

        $catalogPlant->setRelation('plantCare', $care->fresh());

        return $catalogPlant->plantCare;
    }

    private function repairPlantCatalogLink(Plant $plant): ?CatalogPlant
    {
        if ($plant->catalogPlant) {
            $this->repairCatalogPlant($plant->catalogPlant);

            return $plant->catalogPlant->fresh('plantCare');
        }

        if ($plant->fk_catalog_plant_id) {
            $catalogPlant = CatalogPlant::query()->with('plantCare')->find($plant->fk_catalog_plant_id);

            if ($catalogPlant) {
                $plant->setRelation('catalogPlant', $catalogPlant);
                $this->repairCatalogPlant($catalogPlant);

                return $catalogPlant->fresh('plantCare');
            }
        }

        $canonicalName = $this->canonicalName(
            $plant->effectivePlantCare()?->canonical_name,
            $plant->name
        );

        if (! $canonicalName) {
            return null;
        }

        $catalogPlant = CatalogPlant::query()
            ->with('plantCare')
            ->where('canonical_name', $canonicalName)
            ->first();

        if (! $catalogPlant) {
            $catalogPlant = CatalogPlant::query()->create([
                'name' => $plant->name ?: ucfirst($canonicalName),
                'canonical_name' => $canonicalName,
                'plant_type' => $plant->type?->value ?? $plant->type,
                'fk_plant_care_id' => null,
                'description' => null,
                'source_provider' => 'local',
                'source_quality' => 'default',
                'source_scientific_name' => null,
                'source_family' => null,
                'source_image_url' => null,
                'metadata' => null,
            ]);
        }

        $plant->forceFill([
            'fk_catalog_plant_id' => $catalogPlant->id,
        ])->save();

        $this->plantCareService->ensureLinkedCareProfile($plant);
        $this->repairCatalogPlant($catalogPlant->fresh(['plantCare']));
        $plant->setRelation('catalogPlant', $catalogPlant->fresh('plantCare'));

        return $plant->catalogPlant;
    }

    private function canonicalName(?string $canonicalName, ?string $name): ?string
    {
        return PlantCareName::normalize($canonicalName) ?? PlantCareName::normalize($name);
    }

    /**
     * @return Collection<int, PlantCare>
     */
    private function candidateCareProfilesForCatalog(CatalogPlant $catalogPlant, string $canonicalName): Collection
    {
        $candidateIds = collect();

        if ($catalogPlant->fk_plant_care_id) {
            $candidateIds->push($catalogPlant->fk_plant_care_id);
        }

        $namedCandidates = PlantCare::query()
            ->where(function ($query) use ($canonicalName, $catalogPlant): void {
                $query
                    ->where('canonical_name', $canonicalName)
                    ->orWhere('plant_name', $catalogPlant->name);
            })
            ->get()
            ->pluck('id');

        $candidateIds = $candidateIds->merge($namedCandidates)->unique()->values();

        if ($candidateIds->isEmpty()) {
            return collect();
        }

        return PlantCare::query()
            ->whereIn('id', $candidateIds)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeCatalogCareAttributes(
        PlantCare $care,
        CatalogPlant $catalogPlant,
        ?PlantCare $candidateCare,
        string $canonicalName,
    ): array {
        $plantType = $catalogPlant->plant_type?->value ?? $catalogPlant->plant_type
            ?? $candidateCare?->plant_type?->value ?? $candidateCare?->plant_type;

        return [
            'plant_name' => $care->plant_name ?? $candidateCare?->plant_name ?? $catalogPlant->name,
            'canonical_name' => $canonicalName,
            'plant_type' => $care->plant_type?->value ?? $care->plant_type ?? $plantType,
            'task_type' => $care->task_type?->value ?? $care->task_type ?? $candidateCare?->task_type?->value ?? $candidateCare?->task_type ?? TaskType::Watering->value,
            'condition' => $care->condition?->value ?? $care->condition ?? $candidateCare?->condition?->value ?? $candidateCare?->condition ?? ConditionType::Planted->value,
            'description' => $care->description ?? $candidateCare?->description ?? $catalogPlant->description,
            'conditions' => $care->conditions ?? $candidateCare?->conditions,
            'growing_duration_days' => $care->growing_duration_days ?? $candidateCare?->growing_duration_days,
            'flowering_duration_days' => $care->flowering_duration_days ?? $candidateCare?->flowering_duration_days,
            'germinating_duration_days' => $care->germinating_duration_days ?? $candidateCare?->germinating_duration_days,
            'mature_duration_days' => $care->mature_duration_days ?? $candidateCare?->mature_duration_days,
            'mature_duration_end_days' => $care->mature_duration_end_days ?? $candidateCare?->mature_duration_end_days,
            'mature_end_duration_days' => $care->mature_end_duration_days ?? $candidateCare?->mature_end_duration_days,
            'regenerating_duration_days' => $care->regenerating_duration_days ?? $candidateCare?->regenerating_duration_days,
            'reusable' => $care->reusable ?? $candidateCare?->reusable ?? false,
            'watering_interval_days' => $care->watering_interval_days ?? $candidateCare?->watering_interval_days,
            'fertilizing_interval_days' => $care->fertilizing_interval_days ?? $candidateCare?->fertilizing_interval_days,
            'pest_check_interval_days' => $care->pest_check_interval_days ?? $candidateCare?->pest_check_interval_days,
            'rain_skip_threshold_mm' => $care->rain_skip_threshold_mm ?? $candidateCare?->rain_skip_threshold_mm,
            'frost_temp_threshold_c' => $care->frost_temp_threshold_c ?? $candidateCare?->frost_temp_threshold_c,
            'heat_extra_water_temp_c' => $care->heat_extra_water_temp_c ?? $candidateCare?->heat_extra_water_temp_c,
            'wind_protection_kmh' => $care->wind_protection_kmh ?? $candidateCare?->wind_protection_kmh,
            'source_provider' => $care->source_provider ?? $candidateCare?->source_provider ?? $catalogPlant->source_provider ?? 'local',
            'source_quality' => $care->source_quality ?? $candidateCare?->source_quality ?? $catalogPlant->source_quality ?? 'default',
            'source_perenual_species_id' => $care->source_perenual_species_id ?? $candidateCare?->source_perenual_species_id,
            'source_common_name' => $care->source_common_name ?? $candidateCare?->source_common_name ?? $catalogPlant->name,
            'source_scientific_name' => $care->source_scientific_name ?? $candidateCare?->source_scientific_name ?? $catalogPlant->source_scientific_name,
            'source_family' => $care->source_family ?? $candidateCare?->source_family ?? $catalogPlant->source_family,
            'source_image_url' => $care->source_image_url ?? $candidateCare?->source_image_url ?? $catalogPlant->source_image_url,
        ];
    }

    private function filledCareFieldCount(PlantCare $care): int
    {
        return collect(self::CARE_COMPLETENESS_FIELDS)
            ->filter(fn (string $field) => $care->{$field} !== null)
            ->count();
    }
}
