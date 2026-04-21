<?php

namespace Tests\Feature;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Models\CatalogPlant;
use App\Models\Plant;
use App\Models\PlantCare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class PlantingSnapshotFlowTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_catalog_planting_reuses_shared_catalog_care_when_no_instance_overrides_are_provided(): void
    {
        [$user, $owner] = $this->createGardenOwner('snapshot-owner@example.com');
        Sanctum::actingAs($user);

        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        [$catalogPlant, $catalogCare] = $this->createCatalogPlantWithCare([
            'name' => 'Tomato',
            'plant_type' => PlantType::Vegetable->value,
        ], [
            'plant_name' => 'Tomato',
            'canonical_name' => 'tomato',
            'plant_type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 3,
            'fertilizing_interval_days' => 14,
            'conditions' => 'Full sun and steady moisture',
        ]);

        $response = $this->postJson("/api/plots/{$plot->id}/plants", [
            'fk_catalog_plant_id' => $catalogPlant->id,
            'fk_plant_zone_id' => $zone->id,
            'plant_date' => '2026-04-17',
            'condition' => ConditionType::Planted->value,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Tomato')
            ->assertJsonPath('plant_care.watering_interval_days', 3)
            ->assertJsonPath('plant_care.fertilizing_interval_days', 14)
            ->assertJsonPath('plant_care.conditions', 'Full sun and steady moisture');

        $plantedPlant = Plant::query()->firstOrFail();

        $this->assertSame($catalogPlant->id, $plantedPlant->fk_catalog_plant_id);
        $this->assertSame($catalogCare->id, $plantedPlant->effectivePlantCare()?->id);
        $this->assertSame(1, PlantCare::query()->count());
    }

    public function test_catalog_planting_rejects_instance_level_plant_care_payload(): void
    {
        [$user, $owner] = $this->createGardenOwner('snapshot-override@example.com');
        Sanctum::actingAs($user);

        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        [$catalogPlant] = $this->createCatalogPlantWithCare([
            'name' => 'Pepper',
            'plant_type' => PlantType::Vegetable->value,
        ], [
            'plant_name' => 'Pepper',
            'canonical_name' => 'pepper',
            'plant_type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 5,
            'fertilizing_interval_days' => 21,
            'conditions' => 'Warm and bright',
        ]);

        $this->postJson("/api/plots/{$plot->id}/plants", [
            'fk_catalog_plant_id' => $catalogPlant->id,
            'fk_plant_zone_id' => $zone->id,
            'plant_date' => '2026-04-17',
            'condition' => ConditionType::Planted->value,
            'plant_care' => [
                'watering_interval_days' => 2,
                'conditions' => 'Adjusted for a hotter zone',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plant_care']);

        $this->assertSame(0, Plant::query()->count());
    }

    public function test_catalog_planting_creates_shared_catalog_care_when_catalog_entry_is_missing_it(): void
    {
        [$user, $owner] = $this->createGardenOwner('snapshot-partial@example.com');
        Sanctum::actingAs($user);

        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $catalogPlant = CatalogPlant::query()->create([
            'name' => 'Lettuce',
            'canonical_name' => 'lettuce',
            'plant_type' => PlantType::Vegetable->value,
            'fk_plant_care_id' => null,
            'description' => 'Catalog entry without care profile',
            'source_provider' => 'local',
            'source_quality' => 'partial',
        ]);

        $response = $this->postJson("/api/plots/{$plot->id}/plants", [
            'fk_catalog_plant_id' => $catalogPlant->id,
            'fk_plant_zone_id' => $zone->id,
            'plant_date' => '2026-04-17',
            'condition' => ConditionType::Planted->value,
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Lettuce')
            ->assertJsonPath('plant_care.canonical_name', 'lettuce')
            ->assertJsonPath('plant_care.source_provider', 'local');

        $catalogPlant->refresh();

        $this->assertNotNull($catalogPlant->fk_plant_care_id);
        $this->assertSame($catalogPlant->fk_plant_care_id, (int) $response->json('plantCare.id'));
    }

    public function test_updating_catalog_care_updates_existing_planted_instances_that_have_no_overrides(): void
    {
        [$user, $owner] = $this->createGardenOwner('snapshot-sync@example.com');
        Sanctum::actingAs($user);

        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        [$catalogPlant, $catalogCare] = $this->createCatalogPlantWithCare([
            'name' => 'Cucumber',
            'plant_type' => PlantType::Vegetable->value,
        ], [
            'plant_name' => 'Cucumber',
            'canonical_name' => 'cucumber',
            'plant_type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 4,
            'fertilizing_interval_days' => 18,
            'conditions' => 'Cool but bright',
        ]);

        $created = $this->postJson("/api/plots/{$plot->id}/plants", [
            'fk_catalog_plant_id' => $catalogPlant->id,
            'fk_plant_zone_id' => $zone->id,
            'plant_date' => '2026-04-17',
            'condition' => ConditionType::Planted->value,
        ])->assertCreated();

        $plantId = (int) $created->json('id');
        $plantCareId = (int) $created->json('plantCare.id');

        $this->patchJson("/api/catalog-plants/{$catalogPlant->id}", [
            'plant_care' => [
                'watering_interval_days' => 1,
                'fertilizing_interval_days' => 7,
                'conditions' => 'Catalog changed later',
            ],
        ])->assertOk();

        $this->getJson("/api/plots/{$plot->id}/plants/{$plantId}")
            ->assertOk()
            ->assertJsonPath('plantCare.id', $plantCareId)
            ->assertJsonPath('plant_care.watering_interval_days', 1)
            ->assertJsonPath('plant_care.fertilizing_interval_days', 7)
            ->assertJsonPath('plant_care.conditions', 'Catalog changed later');

        $catalogCare->refresh();

        $this->assertSame(1, $catalogCare->watering_interval_days);
        $this->assertSame(7, $catalogCare->fertilizing_interval_days);
        $this->assertSame('Catalog changed later', $catalogCare->conditions);
        $this->assertSame(1, PlantCare::query()->count());
    }

    /**
     * @param  array<string, mixed>  $catalogOverrides
     * @param  array<string, mixed>  $careOverrides
     * @return array{0: CatalogPlant, 1: PlantCare}
     */
    private function createCatalogPlantWithCare(array $catalogOverrides, array $careOverrides): array
    {
        $care = PlantCare::query()->create(array_merge([
            'description' => 'Catalog care profile',
            'conditions' => 'Catalog conditions',
            'growing_duration_days' => 60,
            'flowering_duration_days' => 18,
            'germinating_duration_days' => 8,
            'mature_duration_days' => 30,
            'mature_duration_end_days' => 12,
            'mature_end_duration_days' => 12,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Catalog Plant',
            'canonical_name' => 'catalog plant',
            'task_type' => 'watering',
            'plant_type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 4,
            'fertilizing_interval_days' => 14,
            'pest_check_interval_days' => 7,
            'rain_skip_threshold_mm' => 8,
            'frost_temp_threshold_c' => 1,
            'heat_extra_water_temp_c' => 30,
            'wind_protection_kmh' => 40,
            'source_provider' => 'local',
            'source_quality' => 'partial',
        ], $careOverrides));

        $catalogPlant = CatalogPlant::query()->create(array_merge([
            'name' => 'Catalog Plant',
            'canonical_name' => $care->canonical_name,
            'plant_type' => $care->plant_type,
            'fk_plant_care_id' => $care->id,
            'description' => $care->description,
            'source_provider' => 'local',
            'source_quality' => 'partial',
        ], $catalogOverrides));

        return [$catalogPlant, $care];
    }
}
