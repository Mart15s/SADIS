<?php

namespace App\Services;

use App\Models\CatalogPlant;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Support\PlantCareName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlantCareService
{
    private const CARE_FIELDS = [
        'description',
        'conditions',
        'growing_duration_days',
        'flowering_duration_days',
        'germinating_duration_days',
        'mature_duration_days',
        'mature_duration_end_days',
        'mature_end_duration_days',
        'regenerating_duration_days',
        'reusable',
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
        'source_provider',
        'source_quality',
        'source_perenual_species_id',
        'source_common_name',
        'source_scientific_name',
        'source_family',
        'source_image_url',
    ];

    public function __construct(
        private readonly PerenualService $perenualService,
        private readonly PlantCareNormalizer $normalizer,
    ) {
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function syncPlantCareConfiguration(
        Plant $plant,
        array $overrides = [],
        ?PlantCare $selectedCare = null,
        bool $resetOverrides = false,
        ?int $speciesId = null,
    ): PlantCare {
        $plant->loadMissing(['catalogPlant.plantCare']);

        $baseCare = $this->resolveBaseCare($plant, $selectedCare, $speciesId);
        $this->assignCareToPlant($plant, $baseCare);

        return $baseCare->fresh() ?? $baseCare;
    }

    public function resolveEffectivePlantCare(Plant $plant, ?int $speciesId = null): PlantCare
    {
        return $this->ensureLinkedCareProfile($plant, $speciesId);
    }

    public function ensureLinkedCareProfile(Plant $plant, ?int $speciesId = null): PlantCare
    {
        $catalogPlant = $this->ensureCatalogPlantLink($plant);

        if ($catalogPlant->plantCare) {
            $this->assignCareToPlant($plant, $catalogPlant->plantCare);

            return $catalogPlant->plantCare;
        }

        if ($speciesId !== null) {
            $exactMatch = PlantCare::query()
                ->where('source_perenual_species_id', $speciesId)
                ->first();

            if ($exactMatch) {
                $this->syncCatalogPlantCare($catalogPlant, $exactMatch, $plant);
                $this->assignCareToPlant($plant, $exactMatch);

                return $exactMatch;
            }
        }

        $initialNames = $this->candidateNames($plant);

        if ($speciesId === null) {
            $localMatch = $this->findReusableCare($initialNames);

            if ($localMatch) {
                $this->touchCanonicalMetadata($localMatch, $initialNames);
                $this->syncCatalogPlantCare($catalogPlant, $localMatch, $plant);
                $this->assignCareToPlant($plant, $localMatch);

                return $localMatch;
            }
        }

        $seed = $this->resolveSeed($plant, $speciesId);
        $names = $this->candidateNames($plant, $seed);

        if (filled($seed['matched_species_id'] ?? null)) {
            $speciesMatch = PlantCare::query()
                ->where('source_perenual_species_id', (int) $seed['matched_species_id'])
                ->first();

            if ($speciesMatch) {
                $updated = $this->updateCareProfile($speciesMatch, $plant, $seed);
                $this->syncCatalogPlantCare($catalogPlant, $updated, $plant);
                $this->assignCareToPlant($plant, $updated);

                return $updated;
            }
        }

        $reusable = $this->findReusableCare($names);

        if ($reusable) {
            $updated = $this->updateCareProfile($reusable, $plant, $seed);
            $this->syncCatalogPlantCare($catalogPlant, $updated, $plant);
            $this->assignCareToPlant($plant, $updated);

            return $updated;
        }

        $created = PlantCare::query()->create(
            $this->normalizer->normalize($plant, $seed)
        );

        $this->syncCatalogPlantCare($catalogPlant, $created, $plant);
        $this->assignCareToPlant($plant, $created);

        return $created;
    }

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>|null  $normalizedResult
     * @return array<string, mixed>
     */
    public function previewLinkedCareProfile(
        Plant $plant,
        ?int $speciesId = null,
        array $seed = [],
        ?array $normalizedResult = null,
    ): array {
        $normalizedResult ??= $this->normalizer->normalizeWithTrace($plant, $seed);
        $names = $this->candidateNames($plant, $seed);

        if ($plant->relationLoaded('catalogPlant') && $plant->catalogPlant?->relationLoaded('plantCare') && $plant->catalogPlant->plantCare) {
            return $this->buildExistingPreview(
                $plant->catalogPlant->plantCare,
                $normalizedResult,
                $names,
                'catalog_plant_relation',
                false,
                'reused_linked_profile',
            );
        }

        if ($plant->fk_catalog_plant_id) {
            $linked = $plant->catalogPlant()->with('plantCare')->first()?->plantCare;

            if ($linked) {
                return $this->buildExistingPreview(
                    $linked,
                    $normalizedResult,
                    $names,
                    'catalog_plant',
                    false,
                    'reused_linked_profile',
                );
            }
        }

        if ($speciesId !== null) {
            $exactMatch = PlantCare::query()
                ->where('source_perenual_species_id', $speciesId)
                ->first();

            if ($exactMatch) {
                return $this->buildExistingPreview(
                    $exactMatch,
                    $normalizedResult,
                    $names,
                    'source_perenual_species_id',
                    false,
                    'reused_existing_species_match',
                );
            }
        }

        $initialNames = $this->candidateNames($plant);

        if ($speciesId === null) {
            $localMatch = $this->findReusableCare($initialNames);

            if ($localMatch) {
                return $this->buildExistingPreview(
                    $localMatch,
                    $normalizedResult,
                    $initialNames,
                    'canonical_name',
                    false,
                    'reused_existing_local_match',
                );
            }
        }

        if (filled($seed['matched_species_id'] ?? null)) {
            $speciesMatch = PlantCare::query()
                ->where('source_perenual_species_id', (int) $seed['matched_species_id'])
                ->first();

            if ($speciesMatch) {
                return $this->buildExistingPreview(
                    $speciesMatch,
                    $normalizedResult,
                    $names,
                    'source_perenual_species_id',
                    true,
                    'updated_existing_species_match',
                );
            }
        }

        $reusable = $this->findReusableCare($names);

        if ($reusable) {
            return $this->buildExistingPreview(
                $reusable,
                $normalizedResult,
                $names,
                'canonical_name',
                true,
                'updated_existing_local_match',
            );
        }

        return [
            'action' => 'create_new_profile',
            'would_create' => true,
            'would_reuse' => false,
            'would_update_existing' => false,
            'matched_existing' => false,
            'matched_existing_by' => null,
            'existing_profile_id' => null,
            'existing_profile' => null,
            'metadata_updates' => [],
            'final_profile' => $normalizedResult['normalized'],
            'field_sources' => $this->traceFieldSources($normalizedResult['trace'], $normalizedResult['normalized']),
        ];
    }

    /**
     * @param  array<int, string>  $names
     */
    private function findReusableCare(array $names): ?PlantCare
    {
        if ($names === []) {
            return null;
        }

        return PlantCare::query()
            ->where(function (Builder $query) use ($names): void {
                foreach ($names as $normalizedName) {
                    $query->orWhereRaw('LOWER(canonical_name) = ?', [$normalizedName])
                        ->orWhereRaw('LOWER(plant_name) = ?', [$normalizedName])
                        ->orWhereRaw('LOWER(source_common_name) = ?', [$normalizedName])
                        ->orWhereRaw('LOWER(source_scientific_name) = ?', [$normalizedName]);
                }
            })
            ->orderByRaw("
                CASE source_quality
                    WHEN 'api_enriched' THEN 0
                    WHEN 'partial' THEN 1
                    WHEN 'default' THEN 2
                    ELSE 3
                END
            ")
            ->orderBy('id')
            ->first();
    }

    private function resolveBaseCare(
        Plant $plant,
        ?PlantCare $selectedCare = null,
        ?int $speciesId = null,
    ): PlantCare
    {
        $catalogPlant = $this->ensureCatalogPlantLink($plant);

        if ($selectedCare) {
            $this->syncCatalogPlantCare($catalogPlant, $selectedCare, $plant);
            $this->assignCareToPlant($plant, $selectedCare);

            return $selectedCare;
        }

        if ($catalogPlant->plantCare) {
            $this->assignCareToPlant($plant, $catalogPlant->plantCare);

            return $catalogPlant->plantCare;
        }

        return $this->ensureLinkedCareProfile($plant, $speciesId);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSeed(Plant $plant, ?int $speciesId): array
    {
        try {
            return $this->perenualService->fetchSpeciesSeed($plant->name, $speciesId);
        } catch (Throwable $exception) {
            Log::warning('Failed to enrich plant care profile from Perenual.', [
                'plant_id' => $plant->id,
                'plant_name' => $plant->name,
                'species_id' => $speciesId,
                'error' => $exception->getMessage(),
            ]);

            return [
                'source_quality' => 'default',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<int, string>
     */
    private function candidateNames(Plant $plant, array $seed = []): array
    {
        $searchMatch = is_array($seed['search_match'] ?? null) ? $seed['search_match'] : [];
        $details = is_array($seed['details'] ?? null) ? $seed['details'] : [];

        return PlantCareName::normalizedList(array_merge(
            [$plant->name],
            [$searchMatch['common_name'] ?? null],
            is_array($searchMatch['scientific_name'] ?? null) ? $searchMatch['scientific_name'] : [$searchMatch['scientific_name'] ?? null],
            is_array($searchMatch['other_name'] ?? null) ? $searchMatch['other_name'] : [$searchMatch['other_name'] ?? null],
            [$details['common_name'] ?? null],
            is_array($details['scientific_name'] ?? null) ? $details['scientific_name'] : [$details['scientific_name'] ?? null],
            is_array($details['other_name'] ?? null) ? $details['other_name'] : [$details['other_name'] ?? null],
        ));
    }

    /**
     * @param  array<string, mixed>  $seed
     */
    private function updateCareProfile(PlantCare $care, Plant $plant, array $seed): PlantCare
    {
        $normalized = $this->normalizer->normalize($plant, $seed);

        if ($this->shouldReplaceExistingProfile($care, $normalized)) {
            $care->fill($normalized);
            $care->save();
        } else {
            $this->touchCanonicalMetadata($care, $this->candidateNames($plant, $seed), $normalized);
        }

        return $care->fresh();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function shouldReplaceExistingProfile(PlantCare $care, array $normalized): bool
    {
        $qualityRank = [
            'api_enriched' => 3,
            'partial' => 2,
            'default' => 1,
        ];

        $currentQuality = $qualityRank[$care->source_quality ?? 'default'] ?? 0;
        $nextQuality = $qualityRank[$normalized['source_quality'] ?? 'default'] ?? 0;

        if ($nextQuality > $currentQuality) {
            return true;
        }

        if ($currentQuality === $nextQuality && $currentQuality > 0) {
            return (int) filled($care->source_perenual_species_id) < (int) filled($normalized['source_perenual_species_id'] ?? null);
        }

        return false;
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<string, mixed>  $normalized
     */
    private function touchCanonicalMetadata(PlantCare $care, array $names, array $normalized = []): void
    {
        $updates = [];

        if (! $care->canonical_name && $names !== []) {
            $updates['canonical_name'] = $names[0];
        }

        foreach ([
            'source_common_name',
            'source_scientific_name',
            'source_family',
            'source_image_url',
            'source_provider',
            'source_quality',
            'source_perenual_species_id',
        ] as $field) {
            if (! filled($care->{$field}) && filled($normalized[$field] ?? null)) {
                $updates[$field] = $normalized[$field];
            }
        }

        if ($updates !== []) {
            $care->forceFill($updates)->save();
        }
    }

    private function assignCareToPlant(Plant $plant, PlantCare $care): void
    {
        if ((bool) $plant->reusable !== (bool) $care->reusable) {
            $plant->forceFill([
                'reusable' => (bool) $care->reusable,
            ])->save();
        }
    }

    private function syncCatalogPlantCare(CatalogPlant $catalogPlant, PlantCare $care, ?Plant $plant = null): void
    {
        if ((int) $catalogPlant->fk_plant_care_id === (int) $care->id) {
            if ($plant) {
                $plant->setRelation('catalogPlant', $catalogPlant->fresh('plantCare'));
            }

            return;
        }

        $catalogPlant->forceFill([
            'fk_plant_care_id' => $care->id,
            'description' => $catalogPlant->description ?? $care->description,
            'source_provider' => $catalogPlant->source_provider ?? $care->source_provider,
            'source_quality' => $catalogPlant->source_quality ?? $care->source_quality,
            'source_scientific_name' => $catalogPlant->source_scientific_name ?? $care->source_scientific_name,
            'source_family' => $catalogPlant->source_family ?? $care->source_family,
            'source_image_url' => $catalogPlant->source_image_url ?? $care->source_image_url,
        ])->save();

        if ($plant) {
            $plant->setRelation('catalogPlant', $catalogPlant->fresh('plantCare'));
        }
    }

    private function ensureCatalogPlantLink(Plant $plant): CatalogPlant
    {
        if ($plant->relationLoaded('catalogPlant') && $plant->catalogPlant) {
            $plant->catalogPlant->loadMissing('plantCare');

            return $plant->catalogPlant;
        }

        if ($plant->fk_catalog_plant_id) {
            $catalogPlant = $plant->catalogPlant()->with('plantCare')->first();

            if ($catalogPlant) {
                $plant->setRelation('catalogPlant', $catalogPlant);

                return $catalogPlant;
            }
        }

        $canonicalName = PlantCareName::normalize($plant->name) ?? 'plant';
        $catalogPlant = CatalogPlant::query()->firstOrCreate(
            ['canonical_name' => $canonicalName],
            [
                'name' => $plant->name ?: ucfirst($canonicalName),
                'plant_type' => $plant->type?->value ?? $plant->type,
                'description' => null,
                'source_provider' => 'local',
                'source_quality' => 'default',
                'source_scientific_name' => null,
                'source_family' => null,
                'source_image_url' => null,
                'metadata' => null,
            ]
        );

        if ((int) $plant->fk_catalog_plant_id !== (int) $catalogPlant->id) {
            $plant->forceFill([
                'fk_catalog_plant_id' => $catalogPlant->id,
            ])->save();
        }

        $catalogPlant->loadMissing('plantCare');
        $plant->setRelation('catalogPlant', $catalogPlant);

        return $catalogPlant;
    }

    /**
     * @param  array<string, mixed>  $normalizedResult
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function buildExistingPreview(
        PlantCare $care,
        array $normalizedResult,
        array $names,
        string $matchedBy,
        bool $allowReplacement,
        string $replaceAction,
    ): array {
        $existing = $this->serializeCareProfile($care);
        $normalized = $normalizedResult['normalized'];
        $metadataUpdates = $this->previewMetadataUpdates($existing, $names, $normalized);
        $shouldReplace = $allowReplacement && $this->shouldReplaceExistingProfile($care, $normalized);

        if ($shouldReplace) {
            return [
                'action' => $replaceAction,
                'would_create' => false,
                'would_reuse' => true,
                'would_update_existing' => true,
                'matched_existing' => true,
                'matched_existing_by' => $matchedBy,
                'existing_profile_id' => $care->id,
                'existing_profile' => $existing,
                'metadata_updates' => [],
                'final_profile' => $normalized,
                'field_sources' => $this->traceFieldSources($normalizedResult['trace'], $normalized),
            ];
        }

        $finalProfile = array_replace($existing, $metadataUpdates);

        return [
            'action' => 'reused_existing_profile',
            'would_create' => false,
            'would_reuse' => true,
            'would_update_existing' => $metadataUpdates !== [],
            'matched_existing' => true,
            'matched_existing_by' => $matchedBy,
            'existing_profile_id' => $care->id,
            'existing_profile' => $existing,
            'metadata_updates' => $metadataUpdates,
            'final_profile' => $finalProfile,
            'field_sources' => $this->buildReusedFieldSources(
                $care->id,
                $matchedBy,
                $existing,
                $metadataUpdates,
                $normalizedResult['trace'],
                $finalProfile,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<int, string>  $names
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function previewMetadataUpdates(array $existing, array $names, array $normalized): array
    {
        $updates = [];

        if (! filled($existing['canonical_name'] ?? null) && $names !== []) {
            $updates['canonical_name'] = $names[0];
        }

        foreach ([
            'source_common_name',
            'source_scientific_name',
            'source_family',
            'source_image_url',
            'source_provider',
            'source_quality',
            'source_perenual_species_id',
        ] as $field) {
            if (! filled($existing[$field] ?? null) && filled($normalized[$field] ?? null)) {
                $updates[$field] = $normalized[$field];
            }
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $trace
     * @param  array<string, mixed>  $finalProfile
     * @return array<string, array<string, mixed>>
     */
    private function traceFieldSources(array $trace, array $finalProfile): array
    {
        $sources = [];

        foreach (self::CARE_FIELDS as $field) {
            $traceEntry = $trace[$field] ?? [
                'value' => $finalProfile[$field] ?? null,
                'status' => 'fallback',
                'source' => 'unknown',
            ];

            $sources[$field] = [
                'value' => $finalProfile[$field] ?? null,
                'source_kind' => $this->mapTraceStatusToSourceKind((string) ($traceEntry['status'] ?? 'fallback')),
                'source_detail' => $traceEntry['source'] ?? 'unknown',
            ];
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $metadataUpdates
     * @param  array<string, mixed>  $trace
     * @param  array<string, mixed>  $finalProfile
     * @return array<string, array<string, mixed>>
     */
    private function buildReusedFieldSources(
        int $careId,
        string $matchedBy,
        array $existing,
        array $metadataUpdates,
        array $trace,
        array $finalProfile,
    ): array {
        $detail = "reused existing local plant_care #{$careId} by {$matchedBy}";
        $sources = [];

        foreach (self::CARE_FIELDS as $field) {
            $sources[$field] = [
                'value' => $finalProfile[$field] ?? null,
                'source_kind' => 'reused_local',
                'source_detail' => $detail,
            ];
        }

        foreach (array_keys($metadataUpdates) as $field) {
            $traceEntry = $trace[$field] ?? [
                'value' => $finalProfile[$field] ?? null,
                'status' => 'fallback',
                'source' => 'unknown',
            ];

            $sources[$field] = [
                'value' => $finalProfile[$field] ?? null,
                'source_kind' => $this->mapTraceStatusToSourceKind((string) ($traceEntry['status'] ?? 'fallback')),
                'source_detail' => $traceEntry['source'] ?? 'unknown',
            ];
        }

        return $sources;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCareProfile(PlantCare $care): array
    {
        $values = [];

        foreach (self::CARE_FIELDS as $field) {
            $value = $care->{$field};

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof \UnitEnum) {
                $value = $value->name;
            }

            $values[$field] = $value;
        }

        return $values;
    }

    private function mapTraceStatusToSourceKind(string $status): string
    {
        return match ($status) {
            'direct', 'direct_api' => 'direct_api',
            'guide_derived' => 'guide_derived',
            'derived', 'structural_derived' => 'structural_derived',
            'family_default' => 'family_default',
            'reused_local' => 'reused_local',
            default => 'global_fallback',
        };
    }
}
