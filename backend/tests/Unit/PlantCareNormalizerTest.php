<?php

namespace Tests\Unit;

use App\Services\PlantCareNormalizer;
use App\Models\Plant;
use Tests\TestCase;

class PlantCareNormalizerTest extends TestCase
{
    public function test_tomato_uses_guide_data_and_normalizes_as_vegetable(): void
    {
        $result = $this->normalize('Tomato', [
            'details' => [
                'common_name' => 'Tomato',
                'scientific_name' => ['Solanum lycopersicum'],
                'family' => 'Solanaceae',
                'cycle' => 'Annual',
                'edible_fruit' => true,
                'cuisine' => 'Mediterranean',
                'watering' => 'average',
                'sunlight' => ['full sun'],
            ],
            'care_guides' => [
                'watering' => 'Water every 3-4 days and more often during hot summer spells.',
                'sunlight' => 'Needs full sun and 6-8 hours of direct sun.',
            ],
        ]);

        $this->assertSame('vegetable', $result['normalized']['plant_type']);
        $this->assertFalse($result['normalized']['reusable']);
        $this->assertSame(4, $result['normalized']['watering_interval_days']);
        $this->assertSame('guide_derived', $result['trace']['watering_interval_days']['status']);
        $this->assertStringContainsString('direct sun', strtolower((string) $result['normalized']['conditions']));
        $this->assertSame('guide_derived', $result['trace']['description']['status']);
    }

    public function test_mint_normalizes_to_herb_from_structural_signals(): void
    {
        $result = $this->normalize('Mint', [
            'details' => [
                'common_name' => 'Mint',
                'scientific_name' => ['Mentha'],
                'family' => 'Lamiaceae',
                'category' => 'Herb',
                'medicinal' => true,
                'watering_general_benchmark' => ['value' => 3],
            ],
            'care_guides' => [
                'sunlight' => 'Prefers partial shade or bright indirect light.',
            ],
        ]);

        $this->assertSame('herb', $result['normalized']['plant_type']);
        $this->assertContains($result['trace']['plant_type']['status'], ['direct_api', 'structural_derived']);
        $this->assertSame(3, $result['normalized']['watering_interval_days']);
        $this->assertStringContainsString('partial shade', strtolower((string) $result['normalized']['conditions']));
    }

    public function test_maple_like_woody_species_is_classified_as_tree_and_stays_reusable(): void
    {
        $result = $this->normalize('Maple', [
            'details' => [
                'common_name' => 'Japanese Maple',
                'scientific_name' => ['Acer palmatum'],
                'family' => 'Sapindaceae',
                'type' => 'Tree',
                'watering' => 'average',
                'sunlight' => ['partial shade'],
            ],
            'care_guides' => [
                'watering' => 'Water once a week and keep the soil evenly moist during establishment.',
                'sunlight' => 'Best in partial shade.',
                'pruning' => 'Prune lightly in late winter to shape the canopy.',
            ],
        ]);

        $this->assertSame('tree', $result['normalized']['plant_type']);
        $this->assertTrue($result['normalized']['reusable']);
        $this->assertContains($result['trace']['plant_type']['status'], ['direct_api', 'structural_derived']);
        $this->assertSame('tree', $result['classification']['profile_group']);
        $this->assertStringContainsString('tree', strtolower((string) $result['trace']['plant_type']['source']));
        $this->assertStringContainsString('partial shade', strtolower((string) $result['normalized']['conditions']));
    }

    public function test_strawberry_normalizes_to_berry_from_identity_signals(): void
    {
        $result = $this->normalize('Strawberry', [
            'details' => [
                'common_name' => 'Strawberry',
                'scientific_name' => ['Fragaria x ananassa'],
                'family' => 'Rosaceae',
                'cycle' => 'Perennial',
                'edible_fruit' => true,
            ],
        ]);

        $this->assertSame('berry', $result['normalized']['plant_type']);
        $this->assertTrue($result['normalized']['reusable']);
        $this->assertSame('berry', $result['classification']['profile_group']);
        $this->assertStringContainsString('berry', strtolower((string) $result['trace']['plant_type']['source']));
    }

    public function test_coneflower_normalizes_to_flower_instead_of_herb(): void
    {
        $result = $this->normalize('Coneflower', [
            'details' => [
                'common_name' => 'Coneflower',
                'scientific_name' => ['Echinacea purpurea'],
                'family' => 'Asteraceae',
                'category' => 'Flower',
                'sunlight' => ['full sun'],
            ],
            'care_guides' => [
                'watering' => 'Water every 4-5 days during dry periods.',
            ],
        ]);

        $this->assertSame('flower', $result['normalized']['plant_type']);
        $this->assertSame('flower', $result['classification']['profile_group']);
        $this->assertContains($result['trace']['plant_type']['status'], ['direct_api', 'structural_derived']);
    }

    public function test_rose_like_woody_ornamental_normalizes_to_shrub(): void
    {
        $result = $this->normalize('Rose', [
            'details' => [
                'common_name' => 'Rose',
                'scientific_name' => ['Rosa spp.'],
                'family' => 'Rosaceae',
                'type' => 'Shrub',
                'sunlight' => ['full sun'],
            ],
            'care_guides' => [
                'pruning' => 'Prune lightly in late winter to maintain shape and flowering.',
            ],
        ]);

        $this->assertSame('shrub', $result['normalized']['plant_type']);
        $this->assertTrue($result['normalized']['reusable']);
        $this->assertSame('shrub', $result['classification']['profile_group']);
    }

    public function test_sparse_unknown_species_uses_non_vegetable_global_fallback_with_trace(): void
    {
        $result = $this->normalize('Mystery Plant', [
            'details' => [
                'common_name' => null,
                'scientific_name' => null,
            ],
        ]);

        $this->assertSame('herb', $result['normalized']['plant_type']);
        $this->assertSame('global_fallback', $result['trace']['plant_type']['status']);
        $this->assertStringContainsString('instead of vegetable', strtolower((string) $result['trace']['plant_type']['source']));
        $this->assertContains($result['trace']['description']['status'], ['global_fallback', 'structural_derived']);
        $this->assertNotNull($result['normalized']['description']);
        $this->assertSame(14, $result['normalized']['germinating_duration_days']);
        $this->assertSame(1.0, $result['normalized']['frost_temp_threshold_c']);
    }

    public function test_range_watering_benchmark_is_parsed_to_midpoint(): void
    {
        $result = $this->normalize('Mint', [
            'details' => [
                'common_name' => 'Mint',
                'scientific_name' => ['Mentha'],
                'watering_general_benchmark' => ['value' => '7-10', 'unit' => 'days'],
            ],
        ]);

        $this->assertSame(9, $result['normalized']['watering_interval_days']);
        $this->assertSame('api.watering_general_benchmark.value', $result['trace']['watering_interval_days']['source']);
    }

    public function test_season_arrays_are_preserved_for_duration_calculation(): void
    {
        $result = $this->normalize('Strawberry', [
            'details' => [
                'common_name' => 'Strawberry',
                'scientific_name' => ['Fragaria x ananassa'],
                'flowering_season' => ['Spring', 'Summer'],
                'harvest_season' => ['Summer', 'Autumn'],
            ],
        ]);

        $this->assertSame(90, $result['normalized']['flowering_duration_days']);
        $this->assertSame(90, $result['normalized']['mature_duration_days']);
    }

    public function test_cucumber_is_classified_as_vegetable_with_structurally_derived_frost_threshold(): void
    {
        $result = $this->normalize('Cucumber', [
            'details' => [
                'common_name' => 'Cucumber',
                'scientific_name' => ['Cucumis sativus'],
                'family' => 'Cucurbitaceae',
                'cycle' => 'Annual',
                'other_name' => ['Garden cucumber'],
            ],
        ]);

        $this->assertSame('vegetable', $result['normalized']['plant_type']);
        $this->assertNotSame('legume', $result['normalized']['plant_type']);
        $this->assertSame(10.0, $result['normalized']['frost_temp_threshold_c']);
        $this->assertSame('structural_derived', $result['trace']['frost_temp_threshold_c']['status']);
        $this->assertStringContainsString('cucurbit', strtolower((string) $result['trace']['plant_type']['source']));
    }

    public function test_pruning_guidance_uses_clean_care_summary(): void
    {
        $result = $this->normalize('Cucumber', [
            'details' => [
                'common_name' => 'Cucumber',
                'scientific_name' => ['Cucumis sativus'],
                'family' => 'Cucurbitaceae',
            ],
            'care_guides' => [
                'pruning' => 'Pruning cucumber plants (cucumis sativus) should be done regularly to improve airflow and growth.',
            ],
        ]);

        $this->assertStringContainsString('Care: requires periodic pruning', (string) $result['normalized']['conditions']);
        $this->assertStringNotContainsString('should b', strtolower((string) $result['normalized']['conditions']));
    }

    public function test_sparse_core_payload_with_guides_is_partial_not_api_enriched(): void
    {
        $result = $this->normalize('Cucumber', [
            'details' => [
                'common_name' => 'Cucumber',
                'scientific_name' => ['Cucumis sativus'],
                'family' => 'Cucurbitaceae',
            ],
            'care_guides' => [
                'watering' => 'Water every 3-4 days.',
                'sunlight' => 'Needs full sun.',
                'pruning' => 'Prune side shoots lightly when needed.',
            ],
        ]);

        $this->assertSame('partial', $result['normalized']['source_quality']);
    }

    public function test_frost_threshold_prefers_hardiness_minimum_temperature(): void
    {
        $result = $this->normalize('Rosemary', [
            'details' => [
                'common_name' => 'Rosemary',
                'scientific_name' => ['Salvia rosmarinus'],
                'hardiness' => [
                    'min' => 4,
                    'max' => 16,
                    'minimum_temperature' => ['celsius' => 4],
                    'maximum_temperature' => ['celsius' => 16],
                ],
            ],
        ]);

        $this->assertSame(4.0, $result['normalized']['frost_temp_threshold_c']);
        $this->assertSame('derived from api.hardiness temperature data', $result['trace']['frost_temp_threshold_c']['source']);
    }

    public function test_germinating_duration_does_not_change_from_watering_guide_alone(): void
    {
        $baseSeed = [
            'details' => [
                'common_name' => 'Tomato',
                'scientific_name' => ['Solanum lycopersicum'],
                'family' => 'Solanaceae',
                'cycle' => 'Annual',
                'edible_fruit' => true,
            ],
        ];
        $withoutGuide = $this->normalize('Tomato', $baseSeed);
        $withGuide = $this->normalize('Tomato', $baseSeed + [
            'care_guides' => [
                'watering' => 'Water every day during establishment.',
            ],
        ]);

        $this->assertSame($withoutGuide['normalized']['germinating_duration_days'], $withGuide['normalized']['germinating_duration_days']);
        $this->assertSame(8, $withGuide['normalized']['germinating_duration_days']);
    }

    public function test_unconfirmed_api_category_does_not_override_family_based_identity(): void
    {
        $result = $this->normalize('Pearly Everlasting', [
            'details' => [
                'common_name' => 'Pearly Everlasting',
                'scientific_name' => ['Anaphalis margaritacea'],
                'family' => 'Asteraceae',
                'category' => 'Legume',
                'sunlight' => ['full sun'],
            ],
            'care_guides' => [
                'watering' => 'Water every 2 days during dry periods.',
            ],
        ]);

        $this->assertNotSame('legume', $result['normalized']['plant_type']);
        $this->assertContains($result['trace']['plant_type']['status'], ['family_default', 'global_fallback', 'structural_derived']);
        $this->assertStringNotContainsString('legume terminology', strtolower((string) $result['trace']['plant_type']['source']));
    }

    public function test_pearly_everlasting_is_classified_as_flower_without_manual_review(): void
    {
        $result = $this->normalize('Pearly Everlasting', [
            'details' => [
                'common_name' => 'pearly everlasting',
                'scientific_name' => ['Anaphalis triplinervis'],
                'family' => 'Asteraceae',
                'sunlight' => ['8 hours of direct sun'],
                'watering' => 'keep the soil evenly moist',
            ],
            'care_guides' => [
                'pruning' => 'Requires periodic pruning.',
            ],
        ]);

        $this->assertSame('flower', $result['normalized']['plant_type']);
        $this->assertSame('flower', $result['classification']['profile_group']);
        $this->assertSame('structural_derived', $result['trace']['plant_type']['status']);
        $this->assertStringContainsString('ornamental flower identity signals', strtolower((string) $result['trace']['plant_type']['source']));
        $this->assertNotContains('food_crop', $result['classification']['traits']);
    }

    private function normalize(string $plantName, array $seed): array
    {
        $normalizer = app(PlantCareNormalizer::class);
        $plant = new Plant([
            'name' => $plantName,
        ]);

        return $normalizer->normalizeWithTrace($plant, $seed);
    }
}
