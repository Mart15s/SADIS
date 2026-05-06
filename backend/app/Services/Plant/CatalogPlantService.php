<?php

namespace App\Services\Plant;

use App\Enums\ConditionType;
use App\Models\CatalogPlant;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Services\Integrations\PerenualService;
use App\Support\PlantCareName;
use Illuminate\Support\Arr;

class CatalogPlantService
{
    public function __construct(
        private readonly PerenualService $perenualService,
        private readonly PlantCareNormalizer $plantCareNormalizer,
    ) {
    }

    private const CATALOG_FIELDS = [
        'name',
        'plant_type',
        'description',
        'source_provider',
        'source_quality',
        'source_scientific_name',
        'source_family',
        'source_image_url',
        'metadata',
    ];

    private const CARE_FIELDS = [
        'description',
        'conditions',
        'plant_name',
        'canonical_name',
        'task_type',
        'plant_type',
        'condition',
        'watering_interval_days',
        'fertilizing_interval_days',
        'pest_check_interval_days',
        'rain_skip_threshold_mm',
        'frost_temp_threshold_c',
        'heat_extra_water_temp_c',
        'wind_protection_kmh',
        'reusable',
        'growing_duration_days',
        'germinating_duration_days',
        'flowering_duration_days',
        'mature_duration_days',
        'mature_duration_end_days',
        'mature_end_duration_days',
        'regenerating_duration_days',
        'source_provider',
        'source_quality',
        'source_perenual_species_id',
        'source_common_name',
        'source_scientific_name',
        'source_family',
        'source_image_url',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveCatalogPlant(array $payload, ?CatalogPlant $catalogPlant = null): CatalogPlant
    {
        $catalogPlant ??= new CatalogPlant();
        $previousCareId = $catalogPlant->fk_plant_care_id;

        $canonicalName = $this->canonicalName(
            $payload['canonical_name'] ?? $catalogPlant->canonical_name,
            $payload['name'] ?? $catalogPlant->name
        );

        $plantCare = $this->resolvePlantCare($payload, $catalogPlant, $canonicalName);
        $catalogAttributes = Arr::only($payload, self::CATALOG_FIELDS);

        $catalogPlant->fill(array_merge($catalogAttributes, [
            'canonical_name' => $canonicalName,
            'fk_plant_care_id' => $plantCare?->id,
            'description' => $catalogAttributes['description'] ?? $plantCare?->description,
            'source_provider' => $catalogAttributes['source_provider'] ?? $plantCare?->source_provider,
            'source_quality' => $catalogAttributes['source_quality'] ?? $plantCare?->source_quality,
            'source_scientific_name' => $catalogAttributes['source_scientific_name'] ?? $plantCare?->source_scientific_name,
            'source_family' => $catalogAttributes['source_family'] ?? $plantCare?->source_family,
            'source_image_url' => $catalogAttributes['source_image_url'] ?? $plantCare?->source_image_url,
        ]));
        $catalogPlant->save();

        $this->syncPlantsFromCatalog(
            $catalogPlant->fresh(['plantCare']),
            $previousCareId
        );

        return $catalogPlant->fresh(['plantCare']);
    }

    public function assignCatalogPlantToPlant(Plant $plant, CatalogPlant $catalogPlant): void
    {
        $catalogPlant->loadMissing('plantCare');

        $plant->forceFill([
            'fk_catalog_plant_id' => $catalogPlant->id,
            'name' => $catalogPlant->name,
            'type' => $catalogPlant->plant_type?->value ?? $catalogPlant->plant_type,
            'reusable' => (bool) ($catalogPlant->plantCare?->reusable ?? $plant->reusable),
        ])->save();

        $plant->setRelation('catalogPlant', $catalogPlant);
    }

    public function syncPlantsFromCatalog(CatalogPlant $catalogPlant, ?int $previousCareId = null): void
    {
        $plantQuery = Plant::query()->where('fk_catalog_plant_id', $catalogPlant->id);

        $plantQuery->update([
            'name' => $catalogPlant->name,
            'type' => $catalogPlant->plant_type?->value ?? $catalogPlant->plant_type,
            'reusable' => (bool) ($catalogPlant->plantCare?->reusable ?? false),
        ]);
    }

    public function canonicalName(?string $canonicalName, ?string $name): string
    {
        return PlantCareName::normalize($canonicalName) ?? PlantCareName::normalize($name) ?? 'plant';
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPerenualDraft(int $speciesId): array
    {
        $seed = $this->perenualService->fetchSpeciesSeed('', $speciesId);
        $details = is_array($seed['details'] ?? null) ? $seed['details'] : [];
        $resolvedName = $this->resolveImportedPlantName($details, $seed);
        $previewPlant = new Plant([
            'name' => $resolvedName,
            'condition' => ConditionType::Planted->value,
        ]);
        $normalizedResult = $this->plantCareNormalizer->normalizeWithTrace($previewPlant, $seed);
        $normalizedCare = $normalizedResult['normalized'];
        $trace = is_array($normalizedResult['trace'] ?? null) ? $normalizedResult['trace'] : [];
        $catalogDescription = $this->stringOrNull($details['description'] ?? null) ?? $normalizedCare['description'] ?? null;

        return [
            'species_id' => $speciesId,
            'catalog' => [
                'name' => $resolvedName,
                'canonical_name' => $normalizedCare['canonical_name'] ?? $this->canonicalName(null, $resolvedName),
                'plant_type' => $this->trustedCatalogPlantType($normalizedCare, $trace),
                'description' => $catalogDescription,
                'source_provider' => $normalizedCare['source_provider'] ?? 'perenual',
                'source_quality' => $normalizedCare['source_quality'] ?? 'partial',
                'source_scientific_name' => $normalizedCare['source_scientific_name'] ?? null,
                'source_family' => $normalizedCare['source_family'] ?? null,
                'source_image_url' => $normalizedCare['source_image_url'] ?? null,
                'metadata' => array_filter([
                    'classification' => $normalizedResult['classification'] ?? null,
                ]),
            ],
            'plant_care' => Arr::only(
                array_merge($normalizedCare, [
                    'source_perenual_species_id' => $speciesId,
                ]),
                self::CARE_FIELDS
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePlantCare(array $payload, CatalogPlant $catalogPlant, string $canonicalName): ?PlantCare
    {
        $selectedCareId = $payload['fk_plant_care_id'] ?? null;
        $plantCarePayload = is_array($payload['plant_care'] ?? null) ? $payload['plant_care'] : null;
        $existingCare = $catalogPlant->exists ? $catalogPlant->plantCare()->first() : null;

        if ($selectedCareId !== null) {
            $care = PlantCare::query()->findOrFail($selectedCareId);
        } elseif ($existingCare) {
            $care = $existingCare;
        } elseif ($plantCarePayload !== null) {
            $care = new PlantCare();
        } else {
            return null;
        }

        if ($plantCarePayload !== null || ! $care->exists) {
            $catalogName = $payload['name'] ?? $catalogPlant->name;
            $catalogType = $payload['plant_type'] ?? ($catalogPlant->plant_type?->value ?? $catalogPlant->plant_type);
            $catalogDescription = $payload['description'] ?? $catalogPlant->description;
            $catalogSourceProvider = $payload['source_provider'] ?? $catalogPlant->source_provider ?? 'local';
            $catalogSourceQuality = $payload['source_quality'] ?? $catalogPlant->source_quality ?? 'partial';
            $catalogScientificName = $payload['source_scientific_name'] ?? $catalogPlant->source_scientific_name;
            $catalogFamily = $payload['source_family'] ?? $catalogPlant->source_family;
            $catalogImageUrl = $payload['source_image_url'] ?? $catalogPlant->source_image_url;

            $care->fill(Arr::only($plantCarePayload ?? [], self::CARE_FIELDS));
            $care->forceFill([
                'plant_name' => $catalogName,
                'canonical_name' => $canonicalName,
                'plant_type' => $catalogType,
                'description' => $care->description ?? $catalogDescription,
                'source_provider' => $catalogSourceProvider,
                'source_quality' => $catalogSourceQuality,
                'source_common_name' => $catalogName,
                'source_scientific_name' => $catalogScientificName,
                'source_family' => $catalogFamily,
                'source_image_url' => $catalogImageUrl,
                'source_perenual_species_id' => $plantCarePayload['source_perenual_species_id']
                    ?? $payload['perenual_species_id']
                    ?? $care->source_perenual_species_id,
                'task_type' => $care->task_type?->value ?? $care->task_type ?? 'watering',
                'condition' => $care->condition?->value ?? $care->condition ?? 'planted',
            ])->save();
        }

        return $care->fresh();
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $seed
     */
    private function resolveImportedPlantName(array $details, array $seed): string
    {
        $commonName = $this->stringOrNull($details['common_name'] ?? null)
            ?? $this->stringOrNull(data_get($seed, 'search_match.common_name'));

        if ($commonName) {
            return $commonName;
        }

        $scientificName = $this->stringOrNull($details['scientific_name'][0] ?? null)
            ?? $this->stringOrNull($details['scientific_name'] ?? null)
            ?? $this->stringOrNull(data_get($seed, 'search_match.scientific_name.0'))
            ?? $this->stringOrNull(data_get($seed, 'search_match.scientific_name'));

        return $scientificName ?: 'Imported Plant';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $normalizedCare
     * @param  array<string, mixed>  $trace
     */
    private function trustedCatalogPlantType(array $normalizedCare, array $trace): ?string
    {
        $plantTypeTrace = is_array($trace['plant_type'] ?? null) ? $trace['plant_type'] : null;

        if (! $plantTypeTrace) {
            return null;
        }

        $status = (string) ($plantTypeTrace['status'] ?? '');

        if ($status === 'global_fallback') {
            return null;
        }

        return $normalizedCare['plant_type'] ?? null;
    }
}
