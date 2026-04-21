<?php

namespace Tests\Feature;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Models\GardenOwner;
use App\Models\HasPlot;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlantCareLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_manual_create_auto_links_local_default_care_when_api_enrichment_is_unavailable(): void
    {
        [$user, $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/plots/{$plot->id}/plants", [
            'name' => 'Basil',
            'plant_date' => '2026-04-03',
            'type' => PlantType::Herb->value,
            'condition' => ConditionType::Planted->value,
            'fk_plant_zone_id' => $zone->id,
        ]);

        $response->assertCreated();

        $plant = Plant::query()->firstOrFail();
        $care = $plant->fresh('catalogPlant.plantCare')->effectivePlantCare();

        $this->assertSame('default', $care->source_quality);
        $this->assertSame('local', $care->source_provider);
        $this->assertSame('basil', $care->canonical_name);
        $this->assertSame(4, $care->watering_interval_days);
    }

    public function test_manual_create_reuses_existing_local_care_before_any_external_lookup(): void
    {
        [$user, $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $existing = PlantCare::query()->create([
            'description' => 'Reusable tomato care',
            'conditions' => 'full sun',
            'germinating_duration_days' => 8,
            'growing_duration_days' => 30,
            'flowering_duration_days' => 12,
            'mature_duration_days' => 20,
            'mature_duration_end_days' => 14,
            'mature_end_duration_days' => 14,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Tomato',
            'canonical_name' => 'tomato',
            'task_type' => 'watering',
            'plant_type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 3,
            'fertilizing_interval_days' => 12,
            'pest_check_interval_days' => 6,
            'rain_skip_threshold_mm' => 7,
            'frost_temp_threshold_c' => 2,
            'heat_extra_water_temp_c' => 29,
            'wind_protection_kmh' => 40,
            'source_provider' => 'local',
            'source_quality' => 'default',
        ]);

        Http::preventStrayRequests();

        $response = $this->postJson("/api/plots/{$plot->id}/plants", [
            'name' => 'tomato',
            'plant_date' => '2026-04-03',
            'type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'fk_plant_zone_id' => $zone->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('plantCare.canonical_name', 'tomato');

        $plant = Plant::query()->firstOrFail();
        $linkedCare = $plant->fresh('catalogPlant.plantCare')->effectivePlantCare();

        $this->assertSame($existing->id, $linkedCare->id);
        $this->assertSame(1, PlantCare::query()->count());
        $this->assertSame($existing->watering_interval_days, $linkedCare->watering_interval_days);
        $this->assertSame($existing->canonical_name, $linkedCare->canonical_name);
    }

    public function test_catalog_create_builds_partial_normalized_profile_from_sparse_perenual_data(): void
    {
        [$user, $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::preventStrayRequests();
        Http::fake([
            'https://perenual.test/api/species/details/830*' => Http::response([
                'id' => 830,
                'common_name' => 'Mint',
                'scientific_name' => ['Mentha'],
            ], 200),
            'https://perenual.test/api/species-care-guide-list*' => Http::response([
                'data' => [
                    [
                        'section' => [
                            [
                                'type' => 'watering',
                                'description' => 'Water every 3 days during active growth.',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/plots/{$plot->id}/plants", [
            'name' => 'Mint',
            'plant_date' => '2026-04-03',
            'type' => PlantType::Herb->value,
            'condition' => ConditionType::Planted->value,
            'fk_plant_zone_id' => $zone->id,
            'from_catalog' => true,
            'perenual_species_id' => 830,
        ]);

        $response->assertCreated();

        $plant = Plant::query()->firstOrFail();
        $care = $plant->fresh('catalogPlant.plantCare')->effectivePlantCare();

        $this->assertSame('partial', $care->source_quality);
        $this->assertSame('perenual', $care->source_provider);
        $this->assertSame(3, $care->watering_interval_days);
        $this->assertSame(21, $care->fertilizing_interval_days);
        $this->assertSame(830, $care->source_perenual_species_id);
    }

    public function test_catalog_create_falls_back_to_default_local_profile_when_perenual_is_rate_limited(): void
    {
        [$user, $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::preventStrayRequests();
        Http::fake([
            'https://perenual.test/api/species/details/5021*' => Http::response([], 429),
        ]);

        $response = $this->postJson("/api/plots/{$plot->id}/plants", [
            'name' => 'Tomato',
            'plant_date' => '2026-04-03',
            'type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'fk_plant_zone_id' => $zone->id,
            'from_catalog' => true,
            'perenual_species_id' => 5021,
        ]);

        $response->assertCreated();

        $plant = Plant::query()->firstOrFail();
        $care = $plant->fresh('catalogPlant.plantCare')->effectivePlantCare();

        $this->assertSame('default', $care->source_quality);
        $this->assertSame('local', $care->source_provider);
        $this->assertSame('tomato', $care->canonical_name);
        $this->assertNull($care->source_perenual_species_id);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/species/details/5021'));
    }

    public function test_plant_detail_view_auto_heals_legacy_plant_with_missing_care(): void
    {
        [$user, $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $care = PlantCare::query()->create([
            'description' => 'Lettuce care',
            'conditions' => 'moderate moisture',
            'germinating_duration_days' => 6,
            'growing_duration_days' => 24,
            'flowering_duration_days' => 8,
            'mature_duration_days' => 18,
            'mature_duration_end_days' => 10,
            'mature_end_duration_days' => 10,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Lettuce',
            'canonical_name' => 'lettuce',
            'task_type' => 'watering',
            'plant_type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 2,
            'fertilizing_interval_days' => 14,
            'pest_check_interval_days' => 7,
            'rain_skip_threshold_mm' => 8,
            'frost_temp_threshold_c' => 2,
            'heat_extra_water_temp_c' => 30,
            'wind_protection_kmh' => 45,
            'source_provider' => 'local',
            'source_quality' => 'default',
        ]);

        $plant = Plant::query()->create([
            'name' => 'lettuce',
            'plant_date' => '2026-04-03',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Planted,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ]);

        $this->getJson("/api/plots/{$plot->id}/plants/{$plant->id}")
            ->assertOk()
            ->assertJsonPath('plantCare.source_provider', 'local')
            ->assertJsonPath('plantCare.source_quality', 'default');

        $plant->refresh();

        $this->assertSame($care->id, $plant->fresh('catalogPlant.plantCare')->effectivePlantCare()?->id);
    }

    public function test_manual_create_accepts_boolean_disease_payload_and_returns_linked_care_metadata(): void
    {
        [$user, $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/plots/{$plot->id}/plants", [
            'name' => 'Basil',
            'plant_date' => '2026-04-03',
            'type' => PlantType::Herb->value,
            'condition' => ConditionType::Planted->value,
            'fk_plant_zone_id' => $zone->id,
            'disease' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('disease', false)
            ->assertJsonPath('type', PlantType::Herb->value)
            ->assertJsonPath('plantCare.source_provider', 'local')
            ->assertJsonPath('plantCare.source_quality', 'default');
    }

    private function createOwnedPlotContext(): array
    {
        $user = User::factory()->create();
        $profile = Profile::query()->create([
            'name' => 'Care',
            'surname' => 'Tester',
        ]);

        $owner = GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        $plot = Plot::query()->create([
            'name' => 'Care Plot',
            'city' => 'Vilnius',
            'plot_size' => 42,
            'creation_date' => '2026-04-03',
            'description' => 'Test plot',
            'share' => false,
        ]);

        HasPlot::query()->create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        $zone = PlantZone::query()->create([
            'name' => 'Zone A',
            'zone_size' => 12,
            'soil_type' => SoilType::Clay,
            'rotation_stage' => 0,
            'last_planting_date' => '2026-04-02',
            'fk_plot_id' => $plot->id,
        ]);

        return [$user, $plot, $zone];
    }
}
