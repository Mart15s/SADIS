<?php

namespace Tests\Feature;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Models\CatalogPlant;
use App\Models\Plant;
use App\Models\PlantCare;
use Database\Seeders\LocalPlantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class CatalogPlantApiTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_local_catalog_seeder_creates_twenty_catalog_plants_with_linked_care(): void
    {
        $this->seed(LocalPlantCatalogSeeder::class);
        $this->seed(LocalPlantCatalogSeeder::class);

        $this->assertSame(20, CatalogPlant::query()->count());

        $this->assertDatabaseHas('catalog_plants', [
            'canonical_name' => 'tomato',
            'name' => 'Tomato',
            'plant_type' => PlantType::Vegetable->value,
        ]);

        $catalogPlant = CatalogPlant::query()
            ->where('canonical_name', 'tomato')
            ->firstOrFail();

        $this->assertNotNull($catalogPlant->fk_plant_care_id);
        $this->assertDatabaseHas('plant_care', [
            'id' => $catalogPlant->fk_plant_care_id,
            'canonical_name' => 'tomato',
        ]);
    }

    public function test_catalog_plant_crud_and_detail_work(): void
    {
        [$user] = $this->createGardenOwner('catalog-owner@example.com');
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/catalog-plants', [
            'name' => 'Blueberry',
            'plant_type' => PlantType::Fruit->value,
            'description' => 'Reusable berry shrub',
            'source_provider' => 'local',
            'source_quality' => 'partial',
            'plant_care' => [
                'description' => 'Keep soil acidic and evenly moist.',
                'conditions' => 'Full sun to part shade.',
                'watering_interval_days' => 4,
                'fertilizing_interval_days' => 21,
                'pest_check_interval_days' => 10,
                'reusable' => true,
                'growing_duration_days' => 120,
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Blueberry')
            ->assertJsonPath('plant_type', PlantType::Fruit->value)
            ->assertJsonPath('plant_care.watering_interval_days', 4);

        $catalogPlantId = $created->json('id');

        $this->getJson("/api/catalog-plants/{$catalogPlantId}")
            ->assertOk()
            ->assertJsonPath('canonical_name', 'blueberry')
            ->assertJsonPath('usage_count', 0)
            ->assertJsonPath('plant_care.description', 'Keep soil acidic and evenly moist.');

        $this->patchJson("/api/catalog-plants/{$catalogPlantId}", [
            'name' => 'Blueberry Bush',
            'plant_care' => [
                'description' => 'Updated blueberry care',
                'watering_interval_days' => 3,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Blueberry Bush')
            ->assertJsonPath('canonical_name', 'blueberry')
            ->assertJsonPath('plant_care.description', 'Updated blueberry care')
            ->assertJsonPath('plant_care.watering_interval_days', 3);
    }

    public function test_catalog_plant_delete_is_blocked_when_in_use(): void
    {
        [$user, $owner] = $this->createGardenOwner('catalog-delete@example.com');
        Sanctum::actingAs($user);
        $this->seed(LocalPlantCatalogSeeder::class);

        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $catalogPlant = CatalogPlant::query()->where('canonical_name', 'tomato')->firstOrFail();

        Plant::query()->create([
            'name' => 'Tomato',
            'plant_date' => '2026-04-08',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Planted,
            'fk_plot_id' => $plot->id,
            'plant_zone_id' => $zone->id,
            'fk_plant_zone_id' => $zone->id,
            'fk_catalog_plant_id' => $catalogPlant->id,
            'fk_plant_care_id' => $catalogPlant->fk_plant_care_id,
        ]);

        $this->deleteJson("/api/catalog-plants/{$catalogPlant->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This catalog plant is already used by planted records and cannot be deleted.');

        $this->assertDatabaseHas('catalog_plants', [
            'id' => $catalogPlant->id,
        ]);
    }

    public function test_perenual_preview_returns_prefilled_catalog_and_care_data(): void
    {
        [$user] = $this->createGardenOwner('catalog-preview@example.com');
        Sanctum::actingAs($user);
        Cache::flush();

        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::preventStrayRequests();
        Http::fake([
            'https://perenual.test/api/species/details/5021*' => Http::response([
                'id' => 5021,
                'common_name' => 'Tomato',
                'scientific_name' => ['Solanum lycopersicum'],
                'family' => 'Solanaceae',
                'description' => 'Tomato profile',
                'watering' => 'average',
                'watering_general_benchmark' => [
                    'value' => 2,
                    'unit' => 'days',
                ],
            ], 200),
            'https://perenual.test/api/species-care-guide-list*' => Http::response([
                'data' => [
                    [
                        'section' => [
                            [
                                'type' => 'watering',
                                'description' => 'Water every 2 days during peak heat.',
                            ],
                            [
                                'type' => 'sunlight',
                                'description' => 'Needs full sun.',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/api/catalog-plants/perenual/species/5021')
            ->assertOk()
            ->assertJsonPath('species_id', 5021)
            ->assertJsonPath('catalog.name', 'Tomato')
            ->assertJsonPath('catalog.plant_type', PlantType::Vegetable->value)
            ->assertJsonPath('catalog.metadata.classification.official_plant_type', PlantType::Vegetable->value)
            ->assertJsonPath('catalog.source_provider', 'perenual')
            ->assertJsonPath('catalog.source_scientific_name', 'Solanum lycopersicum')
            ->assertJsonPath('plant_care.source_perenual_species_id', 5021)
            ->assertJsonPath('plant_care.source_family', 'Solanaceae');
    }

    public function test_perenual_preview_classifies_pearly_everlasting_without_manual_type_selection(): void
    {
        [$user] = $this->createGardenOwner('catalog-preview-pearly@example.com');
        Sanctum::actingAs($user);
        Cache::flush();

        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::preventStrayRequests();
        Http::fake([
            'https://perenual.test/api/species/details/795*' => Http::response([
                'id' => 795,
                'common_name' => 'pearly everlasting',
                'scientific_name' => ['Anaphalis triplinervis'],
                'family' => 'Asteraceae',
                'sunlight' => ['8 hours of direct sun'],
                'watering' => 'keep the soil evenly moist',
                'default_image' => [
                    'regular_url' => 'https://images.test/pearly-everlasting.jpg',
                ],
            ], 200),
            'https://perenual.test/api/species-care-guide-list*' => Http::response([
                'data' => [
                    [
                        'section' => [
                            [
                                'type' => 'pruning',
                                'description' => 'Requires periodic pruning.',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/api/catalog-plants/perenual/species/795')
            ->assertOk()
            ->assertJsonPath('catalog.name', 'pearly everlasting')
            ->assertJsonPath('catalog.plant_type', PlantType::Flower->value)
            ->assertJsonPath('catalog.metadata.classification.profile_group', 'flower')
            ->assertJsonPath('catalog.metadata.classification.profile_label', 'Flower')
            ->assertJsonPath('catalog.metadata.classification.classification_status', 'structural_derived')
            ->assertJsonPath('catalog.source_family', 'Asteraceae')
            ->assertJsonPath('plant_care.plant_type', PlantType::Flower->value)
            ->assertJsonPath('plant_care.source_perenual_species_id', 795);
    }

    public function test_perenual_search_returns_meta_and_only_shows_more_when_available(): void
    {
        [$user] = $this->createGardenOwner('catalog-search-meta@example.com');
        Sanctum::actingAs($user);
        Cache::flush();

        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::preventStrayRequests();
        Http::fake([
            'https://perenual.test/api/species-list*' => Http::response([
                'current_page' => 1,
                'last_page' => 2,
                'total' => 6,
                'to' => 3,
                'data' => [
                    ['id' => 1, 'common_name' => 'Blueberry', 'scientific_name' => ['Vaccinium corymbosum']],
                    ['id' => 2, 'common_name' => 'Blueberry Bush', 'scientific_name' => ['Vaccinium']],
                    ['id' => 3, 'common_name' => 'Blueberry Hybrid', 'scientific_name' => ['Vaccinium hybrid']],
                ],
            ], 200),
        ]);

        $this->getJson('/api/catalog-plants/perenual/search?q=blueberry&limit=3')
            ->assertOk()
            ->assertJsonPath('meta.limit', 3)
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.next_limit', 6)
            ->assertJsonCount(3, 'data');
    }

    public function test_catalog_create_persists_selected_perenual_species_id(): void
    {
        [$user] = $this->createGardenOwner('catalog-import-save@example.com');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/catalog-plants', [
            'name' => 'Imported Tomato',
            'plant_type' => PlantType::Vegetable->value,
            'description' => 'Imported from Perenual.',
            'source_provider' => 'perenual',
            'source_quality' => 'partial',
            'source_scientific_name' => 'Solanum lycopersicum',
            'source_family' => 'Solanaceae',
            'perenual_species_id' => 5021,
            'plant_care' => [
                'description' => 'Water every 2 days during peak heat.',
                'watering_interval_days' => 2,
                'fertilizing_interval_days' => 14,
                'reusable' => false,
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Imported Tomato')
            ->assertJsonPath('plant_care.source_provider', 'perenual')
            ->assertJsonPath('plant_care.source_perenual_species_id', 5021);

        $catalogPlant = CatalogPlant::query()->firstOrFail();
        $care = PlantCare::query()->findOrFail($catalogPlant->fk_plant_care_id);

        $this->assertSame(5021, $care->source_perenual_species_id);
        $this->assertSame('perenual', $care->source_provider);
        $this->assertSame('Solanum lycopersicum', $care->source_scientific_name);
    }

    public function test_creating_real_plants_from_same_catalog_plant_in_multiple_zones_works(): void
    {
        [$user, $owner] = $this->createGardenOwner('catalog-place@example.com');
        Sanctum::actingAs($user);
        $this->seed(LocalPlantCatalogSeeder::class);

        $plot = $this->createPlotForOwner($owner);
        $zoneA = $this->createZoneForPlot($plot, ['name' => 'Zone A']);
        $zoneB = $this->createZoneForPlot($plot, ['name' => 'Zone B']);
        $catalogPlant = CatalogPlant::query()->where('canonical_name', 'tomato')->firstOrFail();

        $this->postJson("/api/plots/{$plot->id}/plants", [
            'fk_catalog_plant_id' => $catalogPlant->id,
            'plant_date' => '2026-04-08',
            'condition' => ConditionType::Planted->value,
            'fk_plant_zone_id' => $zoneA->id,
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Tomato')
            ->assertJsonPath('fk_catalog_plant_id', $catalogPlant->id)
            ->assertJsonPath('catalog_plant.name', 'Tomato')
            ->assertJsonPath('plant_care.canonical_name', 'tomato');

        $this->postJson("/api/plots/{$plot->id}/plants", [
            'fk_catalog_plant_id' => $catalogPlant->id,
            'plant_date' => '2026-04-09',
            'condition' => ConditionType::Planted->value,
            'fk_plant_zone_id' => $zoneB->id,
        ])
            ->assertCreated()
            ->assertJsonPath('fk_catalog_plant_id', $catalogPlant->id)
            ->assertJsonPath('catalog_plant.canonical_name', 'tomato');

        $this->assertSame(2, Plant::query()->where('fk_catalog_plant_id', $catalogPlant->id)->count());

        $placedPlant = Plant::query()->where('fk_plant_zone_id', $zoneA->id)->firstOrFail();

        $this->getJson("/api/plots/{$plot->id}/plants/{$placedPlant->id}")
            ->assertOk()
            ->assertJsonPath('catalog_plant.id', $catalogPlant->id)
            ->assertJsonPath('catalog_plant.name', 'Tomato')
            ->assertJsonPath('plant_care.canonical_name', 'tomato');

        $this->getJson("/api/catalog-plants/{$catalogPlant->id}")
            ->assertOk()
            ->assertJsonPath('usage_count', 2)
            ->assertJsonPath('plant_care.canonical_name', 'tomato');
    }
}
