<?php

namespace Tests\Feature;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Models\CatalogPlant;
use App\Models\PlantCare;
use Database\Seeders\LocalPlantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class PlantManagementApiTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_local_catalog_seeder_creates_twenty_curated_profiles_without_duplicates(): void
    {
        $this->seed(LocalPlantCatalogSeeder::class);
        $this->seed(LocalPlantCatalogSeeder::class);

        $this->assertSame(
            20,
            PlantCare::query()
                ->where('source_provider', 'local')
                ->where('source_quality', 'partial')
                ->count()
        );

        $this->assertDatabaseHas('plant_care', [
            'canonical_name' => 'tomato',
            'plant_name' => 'Tomato',
            'plant_type' => PlantType::Vegetable->value,
            'source_provider' => 'local',
            'source_quality' => 'partial',
        ]);
    }

    public function test_local_catalog_search_returns_seeded_profiles(): void
    {
        [$user] = $this->createGardenOwner('searcher@example.com');
        Sanctum::actingAs($user);
        $this->seed(LocalPlantCatalogSeeder::class);

        $this->getJson('/api/plants/catalog?q=tom')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Tomato',
                'canonical_name' => 'tomato',
                'plant_type' => PlantType::Vegetable->value,
                'source_provider' => 'local',
            ]);
    }

    public function test_global_plant_list_and_detail_include_linked_plant_care(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        [$otherUser, $otherOwner] = $this->createGardenOwner('other@example.com');
        $this->seed(LocalPlantCatalogSeeder::class);

        $plot = $this->createPlotForOwner($owner, ['garden_owner_id' => $owner->id]);
        $zone = $this->createZoneForPlot($plot);
        $otherPlot = $this->createPlotForOwner($otherOwner, ['garden_owner_id' => $otherOwner->id]);
        $otherZone = $this->createZoneForPlot($otherPlot);
        $tomatoCare = PlantCare::query()->where('canonical_name', 'tomato')->firstOrFail();
        $cucumberCare = PlantCare::query()->where('canonical_name', 'cucumber')->firstOrFail();

        $plant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Tomato',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Planted,
            'fk_plant_care_id' => $tomatoCare->id,
        ]);

        $this->createPlantForPlot($otherPlot, $otherZone, [
            'name' => 'Cucumber',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Planted,
            'fk_plant_care_id' => $cucumberCare->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/plants')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Tomato')
            ->assertJsonPath('0.plot.name', $plot->name)
            ->assertJsonPath('0.plant_care_summary.canonical_name', 'tomato');

        $this->getJson("/api/plants/{$plant->id}")
            ->assertOk()
            ->assertJsonPath('name', 'Tomato')
            ->assertJsonPath('plantCare.canonical_name', 'tomato')
            ->assertJsonPath('plantCare.source_provider', 'local');
    }

    public function test_global_create_plant_reuses_the_selected_catalog_care(): void
    {
        [$user, $owner] = $this->createGardenOwner('creator@example.com');
        $this->seed(LocalPlantCatalogSeeder::class);

        $plot = $this->createPlotForOwner($owner, ['garden_owner_id' => $owner->id]);
        $zone = $this->createZoneForPlot($plot);
        $catalogPlant = CatalogPlant::query()->where('canonical_name', 'basil')->firstOrFail();
        $care = PlantCare::query()->findOrFail($catalogPlant->fk_plant_care_id);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/plants', [
            'fk_catalog_plant_id' => $catalogPlant->id,
            'condition' => ConditionType::Planted->value,
            'plant_date' => '2026-04-08',
            'fk_plot_id' => $plot->id,
            'fk_plant_zone_id' => $zone->id,
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Basil')
            ->assertJsonPath('plantCare.canonical_name', 'basil');

        $this->assertSame($care->id, (int) $response->json('plantCare.id'));
    }

    public function test_global_update_plant_rejects_instance_level_plant_care_changes(): void
    {
        [$user, $owner] = $this->createGardenOwner('editor@example.com');
        $this->seed(LocalPlantCatalogSeeder::class);

        $plot = $this->createPlotForOwner($owner, ['garden_owner_id' => $owner->id]);
        $zone = $this->createZoneForPlot($plot);
        $care = PlantCare::query()->where('canonical_name', 'tomato')->firstOrFail();
        $catalogPlant = CatalogPlant::query()->where('canonical_name', 'tomato')->firstOrFail();
        Sanctum::actingAs($user);

        $createdPlant = $this->postJson('/api/plants', [
            'fk_catalog_plant_id' => $catalogPlant->id,
            'plant_date' => '2026-04-08',
            'condition' => ConditionType::Planted->value,
            'fk_plot_id' => $plot->id,
            'fk_plant_zone_id' => $zone->id,
        ])->assertCreated();

        $plantId = $createdPlant->json('id');
        $plantCareId = $createdPlant->json('plantCare.id');

        $this->assertSame($care->id, (int) $plantCareId);

        $replacementCare = PlantCare::query()->where('canonical_name', 'cucumber')->firstOrFail();

        $this->patchJson("/api/plants/{$plantId}", [
            'photo_url' => 'https://example.test/tomato.jpg',
            'fk_plant_zone_id' => $zone->id,
            'fk_plant_care_id' => $replacementCare->id,
            'plant_care' => [
                'description' => 'Updated local tomato care',
                'watering_interval_days' => 2,
                'reusable' => false,
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fk_plant_care_id', 'plant_care']);

        $care->refresh();

        $this->assertSame(
            PlantCare::query()->findOrFail($plantCareId)->description,
            $care->description
        );
        $this->assertSame(
            PlantCare::query()->findOrFail($plantCareId)->watering_interval_days,
            $care->watering_interval_days
        );

        $this->assertDatabaseHas('plants', [
            'id' => $plantId,
            'fk_catalog_plant_id' => $catalogPlant->id,
            'photo_url' => null,
        ]);
    }

    public function test_global_delete_plant_works_safely(): void
    {
        [$user, $owner] = $this->createGardenOwner('deleter@example.com');
        $this->seed(LocalPlantCatalogSeeder::class);

        $plot = $this->createPlotForOwner($owner, ['garden_owner_id' => $owner->id]);
        $zone = $this->createZoneForPlot($plot);
        $care = PlantCare::query()->where('canonical_name', 'mint')->firstOrFail();
        $plant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Mint',
            'type' => PlantType::Herb,
            'condition' => ConditionType::Planted,
            'fk_plant_care_id' => $care->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/plants/{$plant->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('plants', ['id' => $plant->id]);
        $this->assertDatabaseHas('plant_care', ['id' => $care->id]);
    }
}
