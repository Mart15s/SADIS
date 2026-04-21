<?php

namespace App\Services;

use App\Enums\PlantType;
use App\Models\Plant;
use App\Support\PlantCareName;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PlantCareNormalizer
{
    public function __construct(
        private readonly PlantCareDefaults $defaults,
    ) {
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    public function normalize(Plant $plant, array $seed = []): array
    {
        return $this->normalizeWithTrace($plant, $seed)['normalized'];
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    public function normalizeWithTrace(Plant $plant, array $seed = []): array
    {
        $raw = $this->mergedRaw($seed);
        $resolvedPlantName = $this->resolvePlantName($plant, $raw);
        $commonName = $this->extractCommonName($raw, $resolvedPlantName);
        $scientificName = $this->extractScientificName($raw);
        $family = $this->stringOrNull($raw['family'] ?? null);
        $imageUrl = $this->resolveImage($raw);
        $guideSections = $this->careGuideSections($raw);
        $signals = $this->buildSignals($plant, $raw, $guideSections, $resolvedPlantName, $commonName, $scientificName, $family);
        $plantType = $this->resolvePlantTypeTrace($plant, $raw, $signals);
        $defaults = $this->defaults->forPlant(
            $plant,
            $resolvedPlantName,
            $plantType['profile_group'],
            $plantType['enum']
        );
        $defaultStatus = $this->defaultStatus((string) $defaults['profile_group']);
        $defaultSource = $this->defaultSourceLabel($defaults);

        $reusable = $this->resolveReusable($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $description = $this->resolveDescription($raw, $signals, $defaults, $resolvedPlantName, $commonName, $scientificName, $family, $defaultStatus, $defaultSource);
        $conditions = $this->resolveConditions($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $growingDuration = $this->resolveGrowingDuration($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $germinatingDuration = $this->resolveGerminatingDuration($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $floweringDuration = $this->resolveFloweringDuration($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $matureDuration = $this->resolveMatureDuration($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $matureDurationEnd = $this->resolveMatureDurationEnd($raw, $signals, $defaults, $reusable['value'], $defaultStatus, $defaultSource);
        $regeneratingDuration = $this->resolveRegeneratingDuration($raw, $signals, $defaults, $reusable['value'], $defaultStatus, $defaultSource);
        $plantName = $this->resolvePlantNameTrace($plant, $raw, $resolvedPlantName);
        $wateringInterval = $this->resolveWateringInterval($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $fertilizingInterval = $this->resolveFertilizingInterval($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $pestCheckInterval = $this->resolvePestCheckInterval($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $rainSkipThreshold = $this->resolveRainThreshold($raw, $signals, $defaults, $wateringInterval['value'], $defaultStatus, $defaultSource);
        $frostThreshold = $this->resolveFrostThreshold($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $heatThreshold = $this->resolveHeatThreshold($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $windProtection = $this->resolveWindThreshold($raw, $signals, $defaults, $defaultStatus, $defaultSource);
        $quality = $this->resolveQuality($seed, $raw, $guideSections, [
            'description' => $description,
            'conditions' => $conditions,
            'growing_duration_days' => $growingDuration,
            'germinating_duration_days' => $germinatingDuration,
            'flowering_duration_days' => $floweringDuration,
            'mature_duration_days' => $matureDuration,
            'mature_duration_end_days' => $matureDurationEnd,
            'regenerating_duration_days' => $regeneratingDuration,
            'reusable' => $reusable,
            'watering_interval_days' => $wateringInterval,
            'fertilizing_interval_days' => $fertilizingInterval,
            'pest_check_interval_days' => $pestCheckInterval,
            'rain_skip_threshold_mm' => $rainSkipThreshold,
            'frost_temp_threshold_c' => $frostThreshold,
            'heat_extra_water_temp_c' => $heatThreshold,
            'wind_protection_kmh' => $windProtection,
        ]);
        $matchedSpeciesId = $seed['matched_species_id'] ?? $raw['id'] ?? null;

        $canonicalName = $this->entry(
            PlantCareName::normalize($resolvedPlantName) ?? PlantCareName::normalize((string) $defaults['plant_name']),
            'structural_derived',
            'derived from the resolved plant name'
        );
        $sourceProvider = $this->entry(
            $quality === 'default' ? 'local' : 'perenual',
            'structural_derived',
            'derived from the resolved source quality'
        );
        $sourceQuality = $this->entry(
            $quality,
            'structural_derived',
            'derived from details completeness and care guide availability'
        );
        $sourcePerenualSpeciesId = is_numeric($matchedSpeciesId)
            ? $this->entry((int) $matchedSpeciesId, 'direct_api', 'seed.matched_species_id/api.id')
            : $this->entry(null, 'global_fallback', 'no matched Perenual species id available');
        $sourceCommonName = $this->pathString($raw, 'common_name') !== null
            ? $this->entry($this->pathString($raw, 'common_name'), 'direct_api', 'api.common_name')
            : $this->entry($commonName, 'structural_derived', 'resolved plant name fallback');
        $sourceScientificName = $scientificName !== null
            ? $this->entry(
                $scientificName,
                'direct_api',
                $this->firstAvailablePath($raw, ['scientific_name.0', 'scientific_name']) ?? 'api.scientific_name'
            )
            : $this->entry(null, 'global_fallback', 'no scientific name available');
        $sourceFamily = $family !== null
            ? $this->entry($family, 'direct_api', 'api.family')
            : $this->entry(null, 'global_fallback', 'no family available');
        $sourceImageUrl = $imageUrl !== null
            ? $this->entry($imageUrl, 'direct_api', $this->resolveImageSourcePath($raw))
            : $this->entry(null, 'global_fallback', 'no usable image available');

        $normalized = [
            'description' => $description['value'],
            'conditions' => $conditions['value'],
            'growing_duration_days' => $growingDuration['value'],
            'germinating_duration_days' => $germinatingDuration['value'],
            'flowering_duration_days' => $floweringDuration['value'],
            'mature_duration_days' => $matureDuration['value'],
            'mature_duration_end_days' => $matureDurationEnd['value'],
            'mature_end_duration_days' => $matureDurationEnd['value'],
            'regenerating_duration_days' => $regeneratingDuration['value'],
            'reusable' => $reusable['value'],
            'plant_name' => $plantName['value'],
            'task_type' => $defaults['task_type'],
            'plant_type' => $plantType['value'],
            'condition' => $defaults['condition'],
            'watering_interval_days' => $wateringInterval['value'],
            'fertilizing_interval_days' => $fertilizingInterval['value'],
            'pest_check_interval_days' => $pestCheckInterval['value'],
            'rain_skip_threshold_mm' => $rainSkipThreshold['value'],
            'frost_temp_threshold_c' => $frostThreshold['value'],
            'heat_extra_water_temp_c' => $heatThreshold['value'],
            'wind_protection_kmh' => $windProtection['value'],
            'canonical_name' => $canonicalName['value'],
            'source_provider' => $sourceProvider['value'],
            'source_quality' => $sourceQuality['value'],
            'source_perenual_species_id' => $sourcePerenualSpeciesId['value'],
            'source_common_name' => $sourceCommonName['value'],
            'source_scientific_name' => $sourceScientificName['value'],
            'source_family' => $sourceFamily['value'],
            'source_image_url' => $sourceImageUrl['value'],
        ];

        return [
            'normalized' => $normalized,
            'classification' => $this->buildClassification($plantType, $defaults, $signals),
            'trace' => [
                'plant_name' => $plantName,
                'description' => $description,
                'task_type' => $this->entry($defaults['task_type'], $defaultStatus, "{$defaultSource} task type"),
                'plant_type' => Arr::except($plantType, ['enum', 'profile_group']),
                'conditions' => $conditions,
                'reusable' => $reusable,
                'watering_interval_days' => $wateringInterval,
                'frost_temp_threshold_c' => $frostThreshold,
                'heat_extra_water_temp_c' => $heatThreshold,
                'growing_duration_days' => $growingDuration,
                'flowering_duration_days' => $floweringDuration,
                'germinating_duration_days' => $germinatingDuration,
                'mature_duration_days' => $matureDuration,
                'mature_duration_end_days' => $matureDurationEnd,
                'mature_end_duration_days' => $matureDurationEnd,
                'regenerating_duration_days' => $regeneratingDuration,
                'fertilizing_interval_days' => $fertilizingInterval,
                'pest_check_interval_days' => $pestCheckInterval,
                'wind_protection_kmh' => $windProtection,
                'condition' => $this->entry($defaults['condition'], $defaultStatus, "{$defaultSource} initial condition"),
                'rain_skip_threshold_mm' => $rainSkipThreshold,
                'canonical_name' => $canonicalName,
                'source_provider' => $sourceProvider,
                'source_quality' => $sourceQuality,
                'source_perenual_species_id' => $sourcePerenualSpeciesId,
                'source_common_name' => $sourceCommonName,
                'source_scientific_name' => $sourceScientificName,
                'source_family' => $sourceFamily,
                'source_image_url' => $sourceImageUrl,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    private function mergedRaw(array $seed): array
    {
        $searchMatch = is_array($seed['search_match'] ?? null) ? $seed['search_match'] : [];
        $details = is_array($seed['details'] ?? null) ? $seed['details'] : [];
        $raw = array_replace_recursive($searchMatch, $details);

        foreach (['scientific_name', 'sunlight', 'other_name'] as $arrayField) {
            if (isset($details[$arrayField]) && is_array($details[$arrayField])) {
                $raw[$arrayField] = $details[$arrayField];
            }
        }

        $careGuides = is_array($seed['care_guides'] ?? null)
            ? $seed['care_guides']
            : (is_array($details['care_guides'] ?? null) ? $details['care_guides'] : []);

        if ($careGuides !== []) {
            $raw['care_guides'] = $careGuides;
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolvePlantName(Plant $plant, array $raw): string
    {
        return $this->stringOrNull($raw['common_name'] ?? null)
            ?? $this->stringOrNull($raw['scientific_name'][0] ?? null)
            ?? $plant->name;
    }

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $guideSections
     */
    private function resolveQuality(array $seed, array $raw, array $guideSections, array $fieldEntries = []): string
    {
        if (($seed['source_quality'] ?? null) === 'default') {
            return 'default';
        }

        $coreSignals = collect([
            $this->stringOrNull($raw['description'] ?? null),
            $this->stringOrNull($raw['watering'] ?? null) ?? $this->parseRangeMidpoint(Arr::get($raw, 'watering_general_benchmark.value')),
            $this->stringOrNull($raw['cycle'] ?? null),
            $this->stringOrNull($raw['care_level'] ?? null),
            $this->stringOrNull($raw['family'] ?? null),
            $this->extractScientificName($raw),
            $this->resolveImage($raw),
        ])->filter(fn (mixed $value) => filled($value))
            ->count();
        $guideCount = count($guideSections);
        $matchedSpeciesId = $seed['matched_species_id'] ?? $raw['id'] ?? null;
        $fieldSourceKinds = collect($fieldEntries)
            ->filter(fn (mixed $entry) => is_array($entry) && isset($entry['status']))
            ->map(fn (array $entry) => (string) $entry['status'])
            ->all();
        $directCount = count(array_filter($fieldSourceKinds, fn (string $status) => $status === 'direct_api'));
        $guideDerivedCount = count(array_filter($fieldSourceKinds, fn (string $status) => $status === 'guide_derived'));
        $derivedCount = count(array_filter($fieldSourceKinds, fn (string $status) => in_array($status, ['structural_derived', 'guide_derived', 'direct_api'], true)));

        if (($coreSignals >= 5 && $guideCount >= 2) || $directCount >= 6 || ($directCount + $guideDerivedCount) >= 8) {
            return 'api_enriched';
        }

        if (
            $guideCount >= 1
            || $coreSignals >= 3
            || $derivedCount >= 4
            || (($directCount + $guideDerivedCount) >= 3 && $matchedSpeciesId !== null)
        ) {
            return 'partial';
        }

        return 'default';
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractCommonName(array $raw, ?string $fallback = null): ?string
    {
        return $this->stringOrNull($raw['common_name'] ?? null)
            ?? $fallback;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractScientificName(array $raw): ?string
    {
        $value = $raw['scientific_name'] ?? null;

        if (is_array($value)) {
            return Collection::make($value)
                ->map(fn (mixed $name) => $this->stringOrNull($name))
                ->filter()
                ->first();
        }

        return $this->stringOrNull($value);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveImage(array $raw): ?string
    {
        $image = $raw['default_image'] ?? null;

        if (is_array($image)) {
            foreach (['regular_url', 'original_url', 'medium_url', 'small_url', 'thumbnail'] as $key) {
                $value = $this->stringOrNull($image[$key] ?? null);

                if ($value && ! Str::contains(Str::lower($value), 'upgrade_access')) {
                    return $value;
                }
            }
        }

        return $this->stringOrNull($raw['image'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private function careGuideSections(array $raw): array
    {
        $guides = [];

        foreach ((array) ($raw['care_guides'] ?? []) as $type => $value) {
            if (is_string($type)) {
                $text = $this->stringOrNull($value);

                if ($text) {
                    $guides[Str::lower($type)] = $text;
                }

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $sectionType = $this->stringOrNull($value['type'] ?? $value['section'] ?? null);
            $description = $this->stringOrNull($value['description'] ?? null);

            if ($sectionType && $description) {
                $guides[Str::lower($sectionType)] = $description;
            }
        }

        return $guides;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $guideSections
     * @return array<string, mixed>
     */
    private function buildSignals(
        Plant $plant,
        array $raw,
        array $guideSections,
        string $resolvedPlantName,
        ?string $commonName,
        ?string $scientificName,
        ?string $family,
    ): array {
        $identityText = Str::lower(collect([
            $plant->name,
            $resolvedPlantName,
            $commonName,
            $scientificName,
            $family,
            $raw['origin'] ?? null,
            $raw['cycle'] ?? null,
            $raw['growth_rate'] ?? null,
            $raw['cuisine'] ?? null,
            $raw['medicinal'] ?? null,
            $raw['other_name'] ?? null,
        ])->flatten()->filter()->implode(' '));
        $apiTypeSignal = Str::lower(collect([
            $raw['type'] ?? null,
            $raw['category'] ?? null,
        ])->flatten()->filter()->implode(' '));
        $wateringSignal = $this->collectSignals($raw, [
            'watering',
            'watering_general_benchmark.value',
            'watering_period',
        ]).' '.Str::lower($guideSections['watering'] ?? '');
        $sunlightSignal = $this->collectSignals($raw, ['sunlight']);
        $maintenanceSignal = $this->collectSignals($raw, ['care_level', 'maintenance']);
        $soilSignal = $this->collectSignals($raw, ['soil', 'soil_type']);
        $pestSignal = $this->collectSignals($raw, ['pest_susceptibility']);
        $cycle = Str::lower((string) ($raw['cycle'] ?? ''));
        $growthRate = Str::lower((string) ($raw['growth_rate'] ?? ''));
        $wateringGuide = Str::lower($guideSections['watering'] ?? '');
        $sunlightGuide = Str::lower($guideSections['sunlight'] ?? '');
        $pruningGuide = Str::lower($guideSections['pruning'] ?? '');

        $edibleFruit = $this->hasTruthyField($raw, 'edible_fruit')
            || $this->hasTruthyField($raw, 'fruits')
            || Str::contains($identityText, ['berry', 'citrus'])
            || $this->containsAnyWholeWord($identityText, ['apple', 'pear', 'plum', 'peach']);
        $edibleLeaf = $this->hasTruthyField($raw, 'edible_leaf')
            || $this->hasTruthyField($raw, 'leaf')
            || Str::contains($identityText, ['lettuce', 'spinach', 'kale', 'leafy', 'greens']);
        $isWoody = Str::contains($identityText, ['tree', 'shrub', 'woody', 'conifer', 'deciduous', 'evergreen', 'acer', 'pinus', 'quercus']);
        $isTreeLike = $this->containsAnyWholeWord($identityText.' '.$apiTypeSignal, ['tree'])
            || Str::contains($identityText.' '.$apiTypeSignal, ['conifer', 'deciduous', 'evergreen', 'acer', 'pinus', 'quercus', 'malus']);
        $isShrubLike = $this->containsAnyWholeWord($identityText.' '.$apiTypeSignal, ['shrub', 'bush', 'hedge'])
            || Str::contains($identityText.' '.$apiTypeSignal, ['rosa', 'hydrangea', 'azalea', 'currant', 'gooseberry']);
        $isBerryLike = Str::contains($identityText.' '.$apiTypeSignal, [
            'berry',
            'strawberry',
            'blueberry',
            'raspberry',
            'blackberry',
            'currant',
            'gooseberry',
            'fragaria',
            'vaccinium',
            'rubus',
        ]);
        $isFlowerLike = Str::contains($identityText.' '.$apiTypeSignal, [
            'flower',
            'floral',
            'bloom',
            'blooming',
            'daisy',
            'aster',
            'petunia',
            'begonia',
            'marigold',
            'lily',
            'tulip',
            'peony',
            'iris',
            'orchid',
            'bulb',
        ]);
        $isVining = Str::contains($identityText, ['vine', 'vining', 'climber', 'creeper', 'trailing', 'cucurbit']);
        $isDroughtTolerant = $this->hasTruthyField($raw, 'drought_tolerant')
            || Str::contains($identityText.' '.$wateringSignal, ['drought tolerant', 'xeric', 'dry soil', 'low water']);
        $isTropical = $this->hasTruthyField($raw, 'tropical')
            || Str::contains($identityText.' '.$this->collectSignals($raw, ['hardiness.location.full_iframe']), ['tropical', 'subtropical']);
        $isIndoor = $this->hasTruthyField($raw, 'indoor')
            || Str::contains($identityText.' '.$sunlightGuide, ['indoor', 'houseplant', 'bright indirect']);
        $isSucculent = Str::contains($identityText.' '.$apiTypeSignal, ['succulent', 'cactus', 'aloe', 'agave', 'sedum', 'echeveria', 'haworthia']);
        $isOrnamental = Str::contains($identityText.' '.$apiTypeSignal, [
            'ornamental',
            'flower',
            'rose',
            'lily',
            'tulip',
            'peony',
            'hosta',
            'fern',
            'daisy',
            'aster',
            'petunia',
            'begonia',
            'marigold',
            'bulb',
            'groundcover',
            'foliage',
        ]);
        $hasCuisine = filled($this->stringOrNull($raw['cuisine'] ?? null));
        $hasMedicinal = $this->hasTruthyField($raw, 'medicinal') || Str::contains($identityText, ['medicinal', 'aromatic']);
        $isFoodCrop = $edibleFruit
            || $edibleLeaf
            || $hasCuisine
            || $this->containsAnyWholeWord($identityText, [
                'edible',
                'vegetable',
                'fruit',
                'herb',
                'crop',
                'tomato',
                'pepper',
                'cucumber',
                'carrot',
                'beet',
                'onion',
                'garlic',
                'potato',
                'lettuce',
                'cabbage',
                'broccoli',
                'cauliflower',
                'spinach',
                'kale',
                'pumpkin',
                'squash',
                'melon',
                'pea',
                'bean',
                'lentil',
                'chickpea',
                'soy',
                'corn',
                'wheat',
                'oat',
                'barley',
                'rice',
                'strawberry',
                'blueberry',
                'raspberry',
            ]);

        return [
            'identity_text' => $identityText,
            'api_type_signal' => $apiTypeSignal,
            'cycle' => $cycle,
            'growth_rate' => $growthRate,
            'watering_signal' => trim(Str::lower($wateringSignal)),
            'sunlight_signal' => trim(Str::lower($sunlightSignal.' '.$sunlightGuide)),
            'maintenance_signal' => trim(Str::lower($maintenanceSignal.' '.$pruningGuide)),
            'soil_signal' => trim(Str::lower($soilSignal.' '.($guideSections['soil'] ?? ''))),
            'pest_signal' => trim(Str::lower($pestSignal.' '.$this->collectGuideText($guideSections, ['pests', 'pest', 'diseases']))),
            'guide_sections' => $guideSections,
            'guide_watering' => $wateringGuide,
            'guide_sunlight' => $sunlightGuide,
            'guide_pruning' => $pruningGuide,
            'watering_guide_interval' => $this->parseIntervalDaysFromText($wateringGuide),
            'fertilizing_guide_interval' => $this->parseIntervalDaysFromText($this->collectGuideText($guideSections, ['fertilizing', 'fertilizer', 'feeding', 'nutrition'])),
            'pest_guide_interval' => $this->parseIntervalDaysFromText($this->collectGuideText($guideSections, ['pests', 'pest', 'diseases'])),
            'sunlight_summary' => $this->extractSunlightSummary($sunlightSignal, $sunlightGuide),
            'watering_summary' => $this->extractWateringSummary($wateringSignal, $wateringGuide),
            'is_woody' => $isWoody,
            'is_tree_like' => $isTreeLike,
            'is_shrub_like' => $isShrubLike,
            'is_berry_like' => $isBerryLike,
            'is_flower_like' => $isFlowerLike,
            'is_vining' => $isVining,
            'is_drought_tolerant' => $isDroughtTolerant,
            'is_tropical' => $isTropical,
            'is_indoor' => $isIndoor,
            'is_succulent' => $isSucculent,
            'is_ornamental' => $isOrnamental,
            'has_cuisine' => $hasCuisine,
            'has_medicinal' => $hasMedicinal,
            'edible_fruit' => $edibleFruit,
            'edible_leaf' => $edibleLeaf,
            'is_food_crop' => $isFoodCrop,
            'hardiness_temp_c' => $this->extractHardinessTemperatureC($raw),
            'has_heat_water_signal' => Str::contains($wateringGuide, ['heat', 'hot weather', 'summer', 'during drought']),
            'has_dormancy_signal' => Str::contains($wateringGuide.' '.$pruningGuide, ['reduce watering in winter', 'winter dormancy', 'dormant']),
            'flowering_season_days' => $this->seasonSpanDays($this->extractSeasonText($raw['flowering_season'] ?? null)),
            'harvest_season_days' => $this->seasonSpanDays($this->extractSeasonText($raw['harvest_season'] ?? null)),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveDescription(
        array $raw,
        array $signals,
        array $defaults,
        string $resolvedPlantName,
        ?string $commonName,
        ?string $scientificName,
        ?string $family,
        string $defaultStatus,
        string $defaultSource,
    ): array {
        $directDescription = $this->pathString($raw, 'description');

        if ($directDescription && Str::length($directDescription) >= 20) {
            return $this->entry($directDescription, 'direct_api', 'api.description');
        }

        $parts = [];
        $usedGuide = false;

        $plantForm = $this->describePlantForm($signals, (string) $defaults['profile_group']);
        $identityLabel = $commonName ?: $resolvedPlantName;

        if ($scientificName && Str::lower($identityLabel) !== Str::lower($scientificName)) {
            $parts[] = "{$identityLabel} ({$scientificName}) is a {$plantForm}.";
        } else {
            $parts[] = "{$identityLabel} is a {$plantForm}.";
        }

        if ($family) {
            $parts[] = "Family: {$family}.";
        }

        $guideHighlights = $this->guideHighlights($signals);

        if ($guideHighlights !== '') {
            $parts[] = $guideHighlights;
            $usedGuide = true;
        }

        if ($parts !== []) {
            return $this->entry(
                implode(' ', $parts),
                $usedGuide ? 'guide_derived' : 'structural_derived',
                $usedGuide
                    ? 'compiled from plant identity data and care guide highlights'
                    : 'compiled from plant identity and structural care signals'
            );
        }

        return $this->defaultEntry($defaults, 'description', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveConditions(
        array $raw,
        array $signals,
        array $defaults,
        string $defaultStatus,
        string $defaultSource,
    ): array {
        $parts = [];
        $usedGuide = false;

        if ($signals['sunlight_summary']) {
            $parts[] = 'Sunlight: '.$signals['sunlight_summary'];
            $usedGuide = $usedGuide || $this->textUsesGuide((string) $signals['guide_sunlight'], (string) $signals['sunlight_summary']);
        }

        if ($signals['watering_summary']) {
            $parts[] = 'Water: '.$signals['watering_summary'];
            $usedGuide = $usedGuide || $this->textUsesGuide((string) $signals['guide_watering'], (string) $signals['watering_summary']);
        }

        $soilSummary = $this->summarizeSoilSignal((string) $signals['soil_signal']);

        if ($soilSummary) {
            $parts[] = 'Soil: '.$soilSummary;
        }

        $careSummary = $this->summarizeCareSignal((string) $signals['maintenance_signal']);

        if ($careSummary) {
            $parts[] = 'Care: '.$careSummary;
        }

        $climateBits = [];

        if ($signals['is_drought_tolerant']) {
            $climateBits[] = 'drought tolerant';
        }

        if ($signals['is_tropical']) {
            $climateBits[] = 'cold sensitive';
        }

        if ($signals['is_indoor']) {
            $climateBits[] = 'indoor friendly';
        }

        if ($signals['has_dormancy_signal']) {
            $climateBits[] = 'reduced winter watering';
            $usedGuide = true;
        }

        if ($climateBits !== []) {
            $parts[] = 'Notes: '.implode(', ', $climateBits);
        }

        if ($parts !== []) {
            return $this->entry(
                implode('; ', $parts),
                $usedGuide ? 'guide_derived' : 'structural_derived',
                $usedGuide
                    ? 'compiled from care guide watering/sunlight text plus structural signals'
                    : 'compiled from API sunlight, watering, soil, maintenance, and climate signals'
            );
        }

        return $this->defaultEntry($defaults, 'conditions', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveGrowingDuration(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathInt($raw, ['growing_duration_days']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.growing_duration_days');
        }

        $matureDuration = $this->pathInt($raw, ['mature_duration_days']);
        $germinatingDuration = $this->pathInt($raw, ['germinating_duration_days']);

        if ($matureDuration !== null || $germinatingDuration !== null) {
            return $this->entry(
                ($matureDuration ?? 0) + ($germinatingDuration ?? 0),
                'structural_derived',
                'derived from available maturity and germination durations'
            );
        }

        $seasonalWindow = max(
            $signals['harvest_season_days'] ?? 0,
            $signals['flowering_season_days'] ?? 0
        );

        if ($seasonalWindow > 0) {
            return $this->entry(
                $this->boundedInt($seasonalWindow + 14, 14, 365),
                'structural_derived',
                'derived from flowering or harvest season span'
            );
        }

        return $this->defaultEntry($defaults, 'growing_duration_days', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveGerminatingDuration(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathInt($raw, ['germinating_duration_days']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.germinating_duration_days');
        }

        return $this->defaultEntry($defaults, 'germinating_duration_days', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveFloweringDuration(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathInt($raw, ['flowering_duration_days']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.flowering_duration_days');
        }

        if ($signals['flowering_season_days'] !== null) {
            return $this->entry($signals['flowering_season_days'], 'structural_derived', 'derived from api.flowering_season');
        }

        return $this->defaultEntry($defaults, 'flowering_duration_days', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveMatureDuration(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathInt($raw, ['mature_duration_days']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.mature_duration_days');
        }

        if ($signals['harvest_season_days'] !== null) {
            return $this->entry($signals['harvest_season_days'], 'structural_derived', 'derived from api.harvest_season');
        }

        return $this->defaultEntry($defaults, 'mature_duration_days', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveMatureDurationEnd(
        array $raw,
        array $signals,
        array $defaults,
        ?bool $reusable,
        string $defaultStatus,
        string $defaultSource,
    ): array {
        $direct = $this->pathInt($raw, ['mature_duration_end_days', 'mature_end_duration_days']);

        if ($direct !== null) {
            return $this->entry(
                $direct,
                'direct_api',
                $this->firstAvailablePath($raw, ['mature_duration_end_days', 'mature_end_duration_days']) ?? 'api.mature_duration_end_days'
            );
        }

        if ($signals['harvest_season_days'] !== null) {
            return $this->entry(
                $this->boundedInt(
                    (int) round($signals['harvest_season_days'] / ($reusable ? 1.5 : 2)),
                    7,
                    180
                ),
                'structural_derived',
                'derived from api.harvest_season window'
            );
        }

        return $this->defaultEntry($defaults, 'mature_duration_end_days', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveRegeneratingDuration(
        array $raw,
        array $signals,
        array $defaults,
        ?bool $reusable,
        string $defaultStatus,
        string $defaultSource,
    ): array {
        $direct = $this->pathInt($raw, ['regenerating_duration_days']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.regenerating_duration_days');
        }

        if ($reusable === false) {
            return $this->entry(0, 'structural_derived', 'non-reusable lifecycle implies no regeneration window');
        }

        if ($signals['has_dormancy_signal']) {
            return $this->entry(
                (int) ($defaults['regenerating_duration_days'] ?? 30),
                'guide_derived',
                'care guide dormancy wording matched the profile regeneration window'
            );
        }

        return $this->defaultEntry($defaults, 'regenerating_duration_days', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveReusable(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathBool($raw, ['reusable']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.reusable');
        }

        if ($signals['cycle'] === 'perennial') {
            return $this->entry(true, 'structural_derived', 'api.cycle=perennial');
        }

        if ($signals['cycle'] === 'annual') {
            return $this->entry(false, 'structural_derived', 'api.cycle=annual');
        }

        if ($signals['cycle'] === 'biennial') {
            return $this->entry(false, 'structural_derived', 'api.cycle=biennial treated as non-reusable after lifecycle completion');
        }

        if ($signals['is_woody']) {
            return $this->entry(true, 'structural_derived', 'tree/shrub/woody signals indicate a persistent plant');
        }

        if ($signals['is_indoor'] || $signals['is_tropical']) {
            return $this->entry(true, 'structural_derived', 'indoor or tropical habit suggests a persistent plant');
        }

        if (Str::contains((string) $signals['identity_text'], ['forage', 'alfalfa', 'clover', 'grass'])) {
            return $this->entry(true, 'structural_derived', 'forage-style regrowth signals indicate reuse potential');
        }

        if (
            $signals['is_food_crop']
            && ! $signals['is_woody']
            && Str::contains((string) $signals['identity_text'], ['vegetable', 'cereal', 'grain', 'legume', 'bean', 'pea', 'tomato', 'cucumber', 'lettuce'])
        ) {
            return $this->entry(false, 'structural_derived', 'annual crop signals indicate non-reusable lifecycle');
        }

        if (
            ! $signals['is_food_crop']
            && Str::contains((string) $signals['identity_text'], ['perennial', 'bulb', 'hosta', 'fern', 'iris', 'peony', 'daylily', 'shrub', 'tree'])
        ) {
            return $this->entry(true, 'structural_derived', 'ornamental perennial signals indicate a reusable lifecycle');
        }

        if (
            ! $signals['is_food_crop']
            && Str::contains((string) $signals['identity_text'], ['annual', 'petunia', 'marigold', 'zinnia', 'cosmos'])
        ) {
            return $this->entry(false, 'structural_derived', 'ornamental annual signals indicate a non-reusable lifecycle');
        }

        return $this->defaultEntry($defaults, 'reusable', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveWateringInterval(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathInt($raw, ['watering_interval_days']);

        if ($direct !== null) {
            return $this->entry($this->boundedInt($direct, 1, 30), 'direct_api', 'api.watering_interval_days');
        }

        $benchmark = $this->parseRangeMidpoint(data_get($raw, 'watering_general_benchmark.value'));

        if ($benchmark !== null && $benchmark > 0) {
            return $this->entry($this->boundedInt($benchmark, 1, 30), 'direct_api', 'api.watering_general_benchmark.value');
        }

        if ($signals['watering_guide_interval'] !== null) {
            return $this->entry($this->boundedInt((int) $signals['watering_guide_interval'], 1, 30), 'guide_derived', 'parsed from care guide watering text');
        }

        $wateringLevel = Str::lower((string) ($raw['watering'] ?? ''));

        if ($wateringLevel !== '') {
            return $this->entry(
                match (true) {
                    Str::contains($wateringLevel, ['abundant', 'frequent', 'high']) => 2,
                    Str::contains($wateringLevel, ['average', 'moderate']) => 4,
                    Str::contains($wateringLevel, ['minimum', 'low', 'rare']) => 7,
                    default => (int) ($defaults['watering_interval_days'] ?? 4),
                },
                'direct_api',
                'derived from api.watering level'
            );
        }

        return $this->defaultEntry($defaults, 'watering_interval_days', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveFertilizingInterval(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathInt($raw, ['fertilizing_interval_days']);

        if ($direct !== null) {
            return $this->entry($this->boundedInt($direct, 3, 90), 'direct_api', 'api.fertilizing_interval_days');
        }

        if ($signals['fertilizing_guide_interval'] !== null) {
            return $this->entry($this->boundedInt((int) $signals['fertilizing_guide_interval'], 3, 90), 'guide_derived', 'parsed from care guide fertilizing text');
        }

        $maintenance = (string) ($signals['maintenance_signal'] ?? '');

        if ($maintenance !== '') {
            return $this->entry(
                match (true) {
                    preg_match('/\b(high|intensive)\b/i', $maintenance) => 14,
                    preg_match('/\b(low|minimal|easy)\b/i', $maintenance) => 30,
                    default => (int) ($defaults['fertilizing_interval_days'] ?? 21),
                },
                'structural_derived',
                'derived from API maintenance intensity signals'
            );
        }

        return $this->defaultEntry($defaults, 'fertilizing_interval_days', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolvePestCheckInterval(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathInt($raw, ['pest_check_interval_days']);

        if ($direct !== null) {
            return $this->entry($this->boundedInt($direct, 1, 30), 'direct_api', 'api.pest_check_interval_days');
        }

        if ($signals['pest_guide_interval'] !== null) {
            return $this->entry($this->boundedInt((int) $signals['pest_guide_interval'], 1, 30), 'guide_derived', 'parsed from care guide pest text');
        }

        if ((string) ($signals['pest_signal'] ?? '') !== '') {
            return $this->entry(
                max(3, (int) (($defaults['pest_check_interval_days'] ?? 7) - 1)),
                'structural_derived',
                'derived from API pest susceptibility signals'
            );
        }

        return $this->defaultEntry($defaults, 'pest_check_interval_days', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveRainThreshold(
        array $raw,
        array $signals,
        array $defaults,
        ?int $wateringInterval,
        string $defaultStatus,
        string $defaultSource,
    ): array {
        $direct = $this->pathFloat($raw, ['rain_skip_threshold_mm']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.rain_skip_threshold_mm');
        }

        if ($wateringInterval !== null) {
            $derived = 4.0 + min(8.0, $wateringInterval * 1.2);

            if ($signals['is_drought_tolerant']) {
                $derived -= 1.0;
            }

            if (Str::contains((string) ($signals['soil_signal'] ?? ''), ['clay', 'moist'])) {
                $derived += 1.0;
            }

            if (Str::contains((string) ($signals['soil_signal'] ?? ''), ['well-drained', 'well drained', 'sandy'])) {
                $derived -= 1.0;
            }

            return $this->entry(
                round(max(4.0, min(15.0, $derived)), 1),
                'structural_derived',
                'derived from watering interval, drought tolerance, and soil drainage signals'
            );
        }

        return $this->defaultEntry($defaults, 'rain_skip_threshold_mm', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveFrostThreshold(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathFloat($raw, ['frost_temp_threshold_c']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.frost_temp_threshold_c');
        }

        if ($signals['hardiness_temp_c'] !== null) {
            return $this->entry(round((float) $signals['hardiness_temp_c'], 1), 'structural_derived', 'derived from api.hardiness temperature data');
        }

        if ($signals['is_tropical'] || $signals['is_indoor'] || $signals['is_succulent']) {
            return $this->entry(
                round(max((float) ($defaults['frost_temp_threshold_c'] ?? 8.0), 6.0), 1),
                'structural_derived',
                'derived from tropical, indoor, or succulent cold-sensitivity signals'
            );
        }

        if (Str::contains((string) ($signals['identity_text'] ?? ''), ['tomato', 'pepper', 'cucumber', 'basil', 'melon', 'squash'])) {
            return $this->entry(10.0, 'structural_derived', 'warm-season crop signals imply frost sensitivity around 10C');
        }

        return $this->defaultEntry($defaults, 'frost_temp_threshold_c', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveHeatThreshold(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathFloat($raw, ['heat_extra_water_temp_c']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.heat_extra_water_temp_c');
        }

        if ($signals['is_drought_tolerant']) {
            return $this->entry(34.0, 'structural_derived', 'drought-tolerant profile allows a higher heat-trigger threshold');
        }

        if ($signals['has_heat_water_signal'] || Str::contains((string) ($signals['sunlight_signal'] ?? ''), ['full sun'])) {
            return $this->entry(
                (float) ($signals['is_indoor'] ? 29.0 : 30.0),
                'structural_derived',
                'derived from heat-related watering guidance and sunlight exposure'
            );
        }

        return $this->defaultEntry($defaults, 'heat_extra_water_temp_c', $defaultStatus, $defaultSource);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveWindThreshold(array $raw, array $signals, array $defaults, string $defaultStatus, string $defaultSource): array
    {
        $direct = $this->pathFloat($raw, ['wind_protection_kmh']);

        if ($direct !== null) {
            return $this->entry($direct, 'direct_api', 'api.wind_protection_kmh');
        }

        if ($signals['is_vining']) {
            return $this->entry(32.0, 'structural_derived', 'vining growth habit benefits from earlier wind protection');
        }

        if ($signals['is_woody']) {
            return $this->entry(60.0, 'structural_derived', 'woody form tolerates stronger wind before protection is needed');
        }

        if (Str::contains((string) ($signals['identity_text'] ?? ''), ['grain', 'cereal', 'forage', 'grass'])) {
            return $this->entry(50.0, 'structural_derived', 'upright grain or forage habit suggests a higher wind threshold');
        }

        return $this->defaultEntry($defaults, 'wind_protection_kmh', $defaultStatus, $defaultSource);
    }

    private function entry(mixed $value, string $status, string $source): array
    {
        return [
            'value' => $value,
            'status' => $status,
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function defaultEntry(array $defaults, string $key, string $defaultStatus, string $defaultSource): array
    {
        return $this->entry(
            $defaults[$key] ?? null,
            $defaultStatus,
            "{$defaultSource} {$key}"
        );
    }

    /**
     * @param  array{value:string,status:string,source:string,enum:PlantType,profile_group:string}  $plantType
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function buildClassification(array $plantType, array $defaults, array $signals): array
    {
        $profileGroup = (string) ($plantType['profile_group'] ?? 'general');
        $traits = [];

        if ($signals['is_woody'] ?? false) {
            $traits[] = 'woody';
        }

        if ($signals['is_tree_like'] ?? false) {
            $traits[] = 'tree';
        }

        if ($signals['is_shrub_like'] ?? false) {
            $traits[] = 'shrub';
        }

        if ($signals['is_berry_like'] ?? false) {
            $traits[] = 'berry';
        }

        if ($signals['is_flower_like'] ?? false) {
            $traits[] = 'flower';
        }

        if ($signals['is_vining'] ?? false) {
            $traits[] = 'vining';
        }

        if ($signals['is_indoor'] ?? false) {
            $traits[] = 'indoor';
        }

        if ($signals['is_tropical'] ?? false) {
            $traits[] = 'tropical';
        }

        if ($signals['is_succulent'] ?? false) {
            $traits[] = 'succulent';
        }

        if ($signals['is_ornamental'] ?? false) {
            $traits[] = 'ornamental';
        }

        if ($signals['is_food_crop'] ?? false) {
            $traits[] = 'food_crop';
        }

        return [
            'official_plant_type' => $plantType['value'],
            'profile_group' => $profileGroup,
            'profile_label' => $this->classificationLabel($profileGroup, (string) ($defaults['profile_label'] ?? $profileGroup)),
            'classification_status' => $plantType['status'],
            'classification_reason' => $plantType['source'],
            'traits' => array_values(array_unique($traits)),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $paths
     */
    private function pathInt(array $raw, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = $this->intFromRaw($raw, $path);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $paths
     */
    private function pathFloat(array $raw, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = $this->floatFromRaw($raw, $path);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $paths
     */
    private function pathBool(array $raw, array $paths): ?bool
    {
        foreach ($paths as $path) {
            $value = $this->boolFromRaw($raw, $path);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function pathString(array $raw, string $path): ?string
    {
        return $this->stringOrNull(data_get($raw, $path));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $paths
     */
    private function firstAvailablePath(array $raw, array $paths): ?string
    {
        foreach ($paths as $path) {
            if (data_get($raw, $path) !== null) {
                return "api.{$path}";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolvePlantNameTrace(Plant $plant, array $raw, string $resolvedPlantName): array
    {
        if ($this->pathString($raw, 'common_name') !== null) {
            return $this->entry($resolvedPlantName, 'direct_api', 'api.common_name');
        }

        if ($this->pathString($raw, 'scientific_name.0') !== null || $this->pathString($raw, 'scientific_name') !== null) {
            return $this->entry(
                $resolvedPlantName,
                'direct_api',
                $this->firstAvailablePath($raw, ['scientific_name.0', 'scientific_name']) ?? 'api.scientific_name'
            );
        }

        return $this->entry($resolvedPlantName, 'structural_derived', $plant->name !== '' ? 'local plant.name' : 'resolved plant name fallback');
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $signals
     * @return array{value:string,status:string,source:string,enum:PlantType,profile_group:string}
     */
    private function resolvePlantTypeTrace(Plant $plant, array $raw, array $signals): array
    {
        if ($plant->type instanceof PlantType) {
            return [
                'value' => $plant->type->value,
                'status' => 'structural_derived',
                'source' => 'local plant.type',
                'enum' => $plant->type,
                'profile_group' => $plant->type->value,
            ];
        }

        $apiTypeSignal = (string) ($signals['api_type_signal'] ?? '');

        $directMappings = [
            [['berry'], PlantType::Berry, 'api.type/api.category matched berry terminology', 'berry'],
            [['cucurbit', 'cucumis', 'cucurbita'], PlantType::Vegetable, 'api.type/api.category matched cucurbit terminology', 'vegetable'],
            [['tree', 'conifer', 'evergreen', 'deciduous'], PlantType::Tree, 'api.type/api.category matched tree terminology', 'tree'],
            [['shrub', 'bush', 'hedge'], PlantType::Shrub, 'api.type/api.category matched shrub terminology', 'shrub'],
            [['flower', 'floral', 'blooming', 'bulb'], PlantType::Flower, 'api.type/api.category matched flower or bulb terminology', 'flower'],
            [['houseplant', 'indoor', 'tropical', 'palm'], PlantType::Herb, 'api.type/api.category matched indoor or tropical terminology; mapped to official herb enum while using the indoor care profile', 'tropical_indoor'],
            [['vine', 'climber', 'creeper', 'trailing'], PlantType::Herb, 'api.type/api.category matched vine terminology; mapped to official herb enum while using the vine care profile', 'vine'],
            [['succulent', 'cactus'], PlantType::Herb, 'api.type/api.category matched succulent terminology; mapped to official herb enum while using the succulent care profile', 'succulent'],
        ];

        foreach ($directMappings as [$needles, $enum, $source, $profileGroup]) {
            if (Str::contains($apiTypeSignal, $needles)) {
                return [
                    'value' => $enum->value,
                    'status' => 'direct_api',
                    'source' => $source,
                    'enum' => $enum,
                    'profile_group' => $profileGroup,
                ];
            }
        }

        $identity = (string) $signals['identity_text'];

        if ($signals['is_berry_like'] || ($signals['edible_fruit'] && Str::contains($identity, ['berry', 'currant', 'gooseberry', 'fragaria', 'vaccinium', 'rubus']))) {
            return [
                'value' => PlantType::Berry->value,
                'status' => 'structural_derived',
                'source' => 'derived from berry family/name signals',
                'enum' => PlantType::Berry,
                'profile_group' => 'berry',
            ];
        }

        if ($signals['edible_fruit'] && ! $signals['is_tree_like'] && ! $signals['is_shrub_like'] && ($signals['is_woody'] || Str::contains($identity, ['citrus', 'orchard', 'tree fruit', 'grape', 'kiwi']))) {
            return [
                'value' => PlantType::Fruit->value,
                'status' => 'structural_derived',
                'source' => 'derived from edible fruit evidence without a clearer berry, tree, or shrub identity',
                'enum' => PlantType::Fruit,
                'profile_group' => 'fruit',
            ];
        }

        if ($signals['has_cuisine'] || $signals['has_medicinal']) {
            if (! $signals['is_woody'] && Str::contains($identity, ['herb', 'mint', 'basil', 'oregano', 'thyme', 'sage', 'rosemary', 'parsley', 'cilantro', 'chive', 'dill'])) {
                return [
                    'value' => PlantType::Herb->value,
                    'status' => 'structural_derived',
                    'source' => 'derived from culinary or medicinal herb signals',
                    'enum' => PlantType::Herb,
                    'profile_group' => 'herb',
                ];
            }
        }

        if (Str::contains($identity, ['cucurbit', 'cucumber', 'squash', 'melon', 'zucchini', 'pumpkin', 'gourd', 'cucumis', 'cucurbita', 'cucurbitaceae'])) {
            return [
                'value' => PlantType::Vegetable->value,
                'status' => 'structural_derived',
                'source' => 'derived from cucurbit family/name signals',
                'enum' => PlantType::Vegetable,
                'profile_group' => 'vegetable',
            ];
        }

        if (
            Str::contains($identity, ['fabaceae', 'chickpea'])
            || $this->containsAnyWholeWord($identity, ['legume', 'bean', 'pea', 'lentil', 'soy'])
        ) {
            return [
                'value' => PlantType::Legume->value,
                'status' => 'structural_derived',
                'source' => 'derived from legume family/name signals',
                'enum' => PlantType::Legume,
                'profile_group' => 'legume',
            ];
        }

        if (Str::contains($identity, ['cereal', 'grain', 'wheat', 'barley', 'oat', 'rice', 'maize', 'corn', 'millet', 'sorghum'])) {
            return [
                'value' => PlantType::Cereal->value,
                'status' => 'structural_derived',
                'source' => 'derived from grain/cereal signals',
                'enum' => PlantType::Cereal,
                'profile_group' => 'cereal',
            ];
        }

        if (Str::contains($identity, ['oilseed', 'sunflower', 'rapeseed', 'canola', 'sesame', 'flax', 'mustard'])) {
            return [
                'value' => PlantType::Oilseed->value,
                'status' => 'structural_derived',
                'source' => 'derived from oilseed signals',
                'enum' => PlantType::Oilseed,
                'profile_group' => 'oilseed',
            ];
        }

        if (Str::contains($identity, ['forage', 'fodder', 'alfalfa', 'clover', 'pasture'])) {
            return [
                'value' => PlantType::Forage->value,
                'status' => 'structural_derived',
                'source' => 'derived from forage signals',
                'enum' => PlantType::Forage,
                'profile_group' => 'forage',
            ];
        }

        if ($signals['is_succulent']) {
            return [
                'value' => PlantType::Herb->value,
                'status' => 'structural_derived',
                'source' => 'derived from succulent or cactus identity signals; mapped to official herb enum while using the succulent care profile',
                'enum' => PlantType::Herb,
                'profile_group' => 'succulent',
            ];
        }

        if ($signals['is_flower_like'] && ! $signals['is_food_crop'] && ! $signals['is_woody']) {
            return [
                'value' => PlantType::Flower->value,
                'status' => 'structural_derived',
                'source' => 'derived from ornamental flower identity signals',
                'enum' => PlantType::Flower,
                'profile_group' => 'flower',
            ];
        }

        if ($signals['is_vining'] && ! $signals['is_food_crop']) {
            return [
                'value' => PlantType::Herb->value,
                'status' => 'structural_derived',
                'source' => 'derived from non-edible climbing or trailing growth signals; mapped to official herb enum while using the vine care profile',
                'enum' => PlantType::Herb,
                'profile_group' => 'vine',
            ];
        }

        if (
            $signals['is_food_crop']
            && (
                $signals['edible_leaf']
                || Str::contains($identity, ['vegetable', 'leafy', 'root', 'tuber', 'salad', 'cucumber', 'tomato', 'pepper', 'squash', 'pumpkin'])
                || ($signals['edible_fruit'] && ! $signals['is_woody'])
            )
        ) {
            return [
                'value' => PlantType::Vegetable->value,
                'status' => 'structural_derived',
                'source' => 'derived from edible crop signals without woody fruit evidence',
                'enum' => PlantType::Vegetable,
                'profile_group' => 'vegetable',
            ];
        }

        if ($signals['is_tree_like']) {
            return [
                'value' => PlantType::Tree->value,
                'status' => 'structural_derived',
                'source' => 'derived from tree identity signals',
                'enum' => PlantType::Tree,
                'profile_group' => 'tree',
            ];
        }

        if ($signals['is_shrub_like']) {
            return [
                'value' => PlantType::Shrub->value,
                'status' => 'structural_derived',
                'source' => 'derived from shrub identity signals',
                'enum' => PlantType::Shrub,
                'profile_group' => 'shrub',
            ];
        }

        if ($signals['is_indoor'] || $signals['is_tropical']) {
            return [
                'value' => PlantType::Herb->value,
                'status' => 'structural_derived',
                'source' => 'derived from indoor or tropical signals; mapped to official herb enum while using the indoor care profile',
                'enum' => PlantType::Herb,
                'profile_group' => 'tropical_indoor',
            ];
        }

        return [
            'value' => PlantType::Herb->value,
            'status' => 'global_fallback',
            'source' => 'no reliable agricultural category evidence was available; using herb as the least specific fallback instead of vegetable',
            'enum' => PlantType::Herb,
            'profile_group' => 'sparse_unknown',
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function resolveImageSourcePath(array $raw): string
    {
        $image = $raw['default_image'] ?? null;

        if (is_array($image)) {
            foreach (['regular_url', 'original_url', 'medium_url', 'small_url', 'thumbnail'] as $key) {
                $value = $this->stringOrNull($image[$key] ?? null);

                if ($value && ! Str::contains(Str::lower($value), 'upgrade_access')) {
                    return "api.default_image.{$key}";
                }
            }
        }

        if ($this->pathString($raw, 'image') !== null) {
            return 'api.image';
        }

        return 'no usable image available';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $keys
     */
    private function collectSignals(array $raw, array $keys): string
    {
        return collect($keys)
            ->map(fn (string $key) => data_get($raw, $key))
            ->flatten()
            ->map(function (mixed $value): ?string {
                if (is_array($value)) {
                    return collect($value)
                        ->map(fn (mixed $nested) => $this->stringOrNull($nested))
                        ->filter()
                        ->implode(' ');
                }

                return $this->stringOrNull($value);
            })
            ->filter()
            ->map(fn (string $value) => Str::lower($value))
            ->implode(' ');
    }

    private function collectGuideText(array $guideSections, array $keys): string
    {
        return collect($keys)
            ->map(fn (string $key) => $this->stringOrNull($guideSections[$key] ?? null))
            ->filter()
            ->implode(' ');
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function containsAnyWholeWord(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($this->containsWholeWord($text, $term)) {
                return true;
            }
        }

        return false;
    }

    private function containsWholeWord(string $text, string $term): bool
    {
        if ($text === '' || $term === '') {
            return false;
        }

        return (bool) preg_match(
            '/(^|[^a-z0-9])'.preg_quote(Str::lower($term), '/').'([^a-z0-9]|$)/i',
            Str::lower($text)
        );
    }

    private function summarizeSoilSignal(string $soilSignal): ?string
    {
        if ($soilSignal === '') {
            return null;
        }

        if (Str::contains($soilSignal, ['well-drained', 'well drained'])) {
            return 'well-drained soil';
        }

        if (Str::contains($soilSignal, ['moist', 'evenly moist'])) {
            return 'consistently moist soil';
        }

        if (Str::contains($soilSignal, ['rich', 'fertile'])) {
            return 'nutrient-rich soil';
        }

        return $this->truncateSentence($soilSignal, 60);
    }

    private function summarizeCareSignal(string $careSignal): ?string
    {
        if ($careSignal === '') {
            return null;
        }

        if (preg_match('/\b(low|minimal|easy)\b/i', $careSignal)) {
            return 'low maintenance';
        }

        if (preg_match('/\b(high|intensive)\b/i', $careSignal)) {
            return 'higher maintenance';
        }

        if (preg_match('/\b(moderate|medium)\b/i', $careSignal)) {
            return 'moderate maintenance';
        }

        if (preg_match('/\b(pruning|prune|trim)\b/i', $careSignal)) {
            return 'requires periodic pruning';
        }

        return null;
    }

    private function describePlantForm(array $signals, string $profileGroup): string
    {
        return match (true) {
            $profileGroup === 'tree' => 'woody tree',
            $profileGroup === 'shrub' => 'woody shrub',
            $profileGroup === 'berry' => 'berry plant',
            $profileGroup === 'flower' => 'flowering ornamental plant',
            $profileGroup === 'woody_ornamental' => 'woody perennial ornamental plant',
            $profileGroup === 'fruit' => 'fruiting perennial plant',
            $profileGroup === 'vegetable' => 'food crop',
            $profileGroup === 'herb' => 'culinary or aromatic herb',
            $profileGroup === 'legume' => 'legume crop',
            $profileGroup === 'cereal' => 'grain crop',
            $profileGroup === 'forage' => 'forage plant',
            $profileGroup === 'oilseed' => 'oilseed crop',
            $profileGroup === 'ornamental' => 'ornamental flower or foliage plant',
            $profileGroup === 'vine' => 'climbing or trailing plant',
            $profileGroup === 'succulent' => 'succulent or cactus plant',
            $profileGroup === 'tropical_indoor' => 'tropical or indoor plant',
            $signals['is_woody'] => 'woody plant',
            default => 'general plant',
        };
    }

    private function classificationLabel(string $profileGroup, string $fallback): string
    {
        return match ($profileGroup) {
            'tree' => 'Tree',
            'shrub' => 'Shrub',
            'berry' => 'Berry',
            'flower' => 'Flower',
            'woody_ornamental' => 'Woody ornamental',
            'tropical_indoor' => 'Indoor / tropical',
            'ornamental' => 'Ornamental',
            'vine' => 'Vining plant',
            'succulent' => 'Succulent / cactus',
            'sparse_unknown' => 'Needs manual review',
            default => Str::headline($fallback !== '' ? $fallback : $profileGroup),
        };
    }

    private function guideHighlights(array $signals): string
    {
        $parts = [];

        if ($signals['sunlight_summary']) {
            $parts[] = 'It prefers '.$signals['sunlight_summary'].'.';
        }

        if ($signals['watering_summary']) {
            $parts[] = 'Watering guidance suggests '.$signals['watering_summary'].'.';
        }

        if ($signals['guide_pruning'] !== '') {
            $parts[] = 'Pruning guidance is available for seasonal maintenance.';
        }

        return implode(' ', array_slice($parts, 0, 2));
    }

    private function extractSunlightSummary(string $sunlightSignal, string $sunlightGuide): ?string
    {
        $combined = trim($sunlightGuide.' '.$sunlightSignal);

        if ($combined === '') {
            return null;
        }

        if (preg_match('/(\d+)\s*-\s*(\d+)\s*hours?\s+of\s+direct\s+sun/i', $combined, $matches)) {
            return "{$matches[1]}-{$matches[2]} hours of direct sun";
        }

        if (preg_match('/(\d+)\s*hours?\s+of\s+direct\s+sun/i', $combined, $matches)) {
            return "{$matches[1]} hours of direct sun";
        }

        return match (true) {
            Str::contains($combined, ['full sun']) => 'full sun',
            Str::contains($combined, ['partial shade', 'part shade']) => 'partial shade',
            Str::contains($combined, ['partial sun', 'part sun']) => 'partial sun',
            Str::contains($combined, ['bright indirect', 'indirect light']) => 'bright indirect light',
            Str::contains($combined, ['full shade']) => 'full shade',
            Str::contains($combined, ['shade']) => 'shade',
            default => null,
        };
    }

    private function extractWateringSummary(string $wateringSignal, string $wateringGuide): ?string
    {
        if ($wateringGuide !== '') {
            if (preg_match('/top\s+\d+(?:-\d+)?\s+inches?\s+of\s+soil\s+to\s+dry/i', $wateringGuide)) {
                return 'allow the top soil to dry slightly between waterings';
            }

            if (Str::contains($wateringGuide, ['evenly moist', 'keep the soil moist'])) {
                return 'keep the soil evenly moist';
            }

            $interval = $this->parseIntervalDaysFromText($wateringGuide);

            if ($interval !== null) {
                return $interval === 1 ? 'water daily' : "water about every {$interval} days";
            }
        }

        if ($wateringSignal === '') {
            return null;
        }

        return match (true) {
            Str::contains($wateringSignal, ['daily', 'frequent', 'often', 'high']) => 'frequent watering',
            Str::contains($wateringSignal, ['minimum', 'low', 'rare', 'seldom']) => 'light watering',
            Str::contains($wateringSignal, ['average', 'moderate']) => 'moderate watering',
            default => null,
        };
    }

    private function textUsesGuide(string $guideText, string $summary): bool
    {
        return $guideText !== '' && $summary !== '';
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function intFromRaw(array $raw, string $key): ?int
    {
        $value = data_get($raw, $key);

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function parseRangeMidpoint(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        if (is_string($value) && preg_match('/(\d+)\s*-\s*(\d+)/', $value, $matches)) {
            return (int) round((((int) $matches[1]) + ((int) $matches[2])) / 2);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function floatFromRaw(array $raw, string $key): ?float
    {
        $value = data_get($raw, $key);

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 1);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function boolFromRaw(array $raw, string $key): ?bool
    {
        $value = data_get($raw, $key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (is_string($value) && $value !== '') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $normalized = Str::lower($text);

        if (Str::contains($normalized, [
            'upgrade plan',
            'upgrade plans',
            'subscription-api-pricing',
            "i'm sorry",
            'im sorry',
        ])) {
            return null;
        }

        return $text;
    }

    private function extractSeasonText(mixed $value): ?string
    {
        if (is_array($value)) {
            $parts = collect($value)
                ->map(fn (mixed $item) => $this->stringOrNull($item))
                ->filter()
                ->values()
                ->all();

            return $parts !== [] ? implode(', ', $parts) : null;
        }

        return $this->stringOrNull($value);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function hasTruthyField(array $raw, string $path): bool
    {
        $value = data_get($raw, $path);

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            return in_array(Str::lower(trim($value)), ['1', 'true', 'yes', 'y'], true);
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return false;
    }

    private function parseIntervalDaysFromText(string $text): ?int
    {
        $text = Str::lower($text);

        if ($text === '') {
            return null;
        }

        if (Str::contains($text, ['daily', 'every day', 'once a day'])) {
            return 1;
        }

        if (Str::contains($text, ['every other day'])) {
            return 2;
        }

        if (preg_match('/(?:once|twice|three times|\d+\s*times?)\s+a\s+week/', $text, $matches)) {
            return match (true) {
                Str::contains($matches[0], ['three', '3']) => 2,
                Str::contains($matches[0], ['twice', '2']) => 4,
                default => 7,
            };
        }

        if (preg_match('/every\s+(\d+)\s*-\s*(\d+)\s+days?/', $text, $matches)) {
            return (int) round((((int) $matches[1]) + ((int) $matches[2])) / 2);
        }

        if (preg_match('/every\s+(\d+)\s+days?/', $text, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/every\s+(\d+)\s*-\s*(\d+)\s+weeks?/', $text, $matches)) {
            return (int) round(((((int) $matches[1]) + ((int) $matches[2])) / 2) * 7);
        }

        if (preg_match('/every\s+(\d+)\s+weeks?/', $text, $matches)) {
            return (int) $matches[1] * 7;
        }

        if (Str::contains($text, ['weekly', 'once a week'])) {
            return 7;
        }

        if (Str::contains($text, ['biweekly', 'every two weeks', 'every 2 weeks'])) {
            return 14;
        }

        return null;
    }

    private function seasonSpanDays(?string $text, int $daysPerSeason = 45): ?int
    {
        if (! $text) {
            return null;
        }

        $normalized = Str::lower($text);
        $seasons = 0;

        foreach (['spring', 'summer', 'autumn', 'fall', 'winter'] as $season) {
            if (Str::contains($normalized, $season)) {
                $seasons++;
            }
        }

        if (Str::contains($normalized, ['year round', 'year-round', 'all year'])) {
            return 120;
        }

        return $seasons > 0 ? $seasons * $daysPerSeason : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractHardinessTemperatureC(array $raw): ?float
    {
        foreach ([
            'hardiness.min',
            'hardiness.minimum_temperature.celsius',
            'hardiness.minimum_temperature.deg_c',
        ] as $path) {
            $value = $this->floatFromRaw($raw, $path);

            if ($value !== null && $value >= -40.0 && $value <= 20.0) {
                return $value;
            }
        }

        $hardiness = data_get($raw, 'hardiness');
        $text = $this->stringOrNull(data_get($raw, 'hardiness.location.full_iframe'))
            ?? (is_array($hardiness) ? $this->stringOrNull(json_encode($hardiness)) : null);

        if ($text) {
            foreach ([
                '/min(?:imum)?[^\d-]*(-?\d+(?:\.\d+)?)\s*(?:°|º)?\s*c/i',
                '/(-?\d+(?:\.\d+)?)\s*(?:°|º)?\s*c[^\d]*(?:minimum|min)/i',
                '/(-?\d+(?:\.\d+)?)\s*(?:°|º)?\s*c/i',
            ] as $pattern) {
                if (! preg_match($pattern, $text, $matches)) {
                    continue;
                }

                $value = (float) $matches[1];

                if ($value >= -40.0 && $value <= 20.0) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function boundedInt(int $value, int $min, int $max): int
    {
        return max($min, min($value, $max));
    }

    private function truncateSentence(string $text, int $limit): string
    {
        return Str::limit(Str::of($text)->squish()->value(), $limit, '');
    }

    private function defaultStatus(string $profileGroup): string
    {
        return in_array($profileGroup, ['general', 'sparse_unknown'], true)
            ? 'global_fallback'
            : 'family_default';
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function defaultSourceLabel(array $defaults): string
    {
        return sprintf('central defaults for the %s profile', (string) ($defaults['profile_label'] ?? 'general plant'));
    }
}
