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

class PlantCatalogCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_catalog_create_uses_selected_species_id_to_link_care_profile(): void
    {
        [$user, $plot, $zone] = $this->createOwnedPlotContext();

        Sanctum::actingAs($user);

        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::preventStrayRequests();
        Http::fake([
            'https://perenual.test/api/species/details/5021*' => Http::response([
                'id' => 5021,
                'common_name' => 'tomato',
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

        $response = $this->postJson("/api/plots/{$plot->id}/plants", [
            'name' => 'tomato',
            'plant_date' => '2026-04-03',
            'type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'fk_plant_zone_id' => $zone->id,
            'from_catalog' => true,
            'perenual_species_id' => 5021,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'tomato')
            ->assertJsonPath('plantCare.source_provider', 'perenual')
            ->assertJsonPath('plantCare.source_quality', 'partial')
            ->assertJsonPath('plantCare.source_scientific_name', 'Solanum lycopersicum');

        $plant = Plant::query()->firstOrFail();
        $care = $plant->fresh('catalogPlant.plantCare')->effectivePlantCare();

        $this->assertDatabaseHas('plant_care', [
            'id' => $care->id,
            'plant_name' => 'tomato',
            'canonical_name' => 'tomato',
            'source_perenual_species_id' => 5021,
            'source_quality' => 'partial',
        ]);
        $this->assertStringContainsString('food crop', strtolower((string) $care->description));
        $this->assertSame('Solanum lycopersicum', $care->source_scientific_name);
        $this->assertSame('Solanaceae', $care->source_family);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/species/details/5021'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/species-care-guide-list')
            && (int) data_get($request->data(), 'species_id') === 5021);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/species-list'));
    }

    private function createOwnedPlotContext(): array
    {
        $user = User::factory()->create();
        $profile = Profile::query()->create([
            'name' => 'Catalog',
            'surname' => 'Tester',
        ]);

        $owner = GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        $plot = Plot::query()->create([
            'name' => 'Catalog Plot',
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
