<?php

namespace Tests\Feature;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Models\CatalogPlant;
use App\Models\Plant;
use App\Models\PlantCare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class PlantCareRepairCommandTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_repair_command_relinks_legacy_snapshot_to_shared_catalog_care_and_clears_legacy_overrides(): void
    {
        [, $owner] = $this->createGardenOwner('repair-owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);

        $catalogCare = PlantCare::query()->create([
            'description' => 'Shared tomato care',
            'conditions' => 'Full sun',
            'growing_duration_days' => 60,
            'flowering_duration_days' => 18,
            'germinating_duration_days' => 8,
            'mature_duration_days' => 30,
            'mature_duration_end_days' => 12,
            'mature_end_duration_days' => 12,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Tomato',
            'canonical_name' => 'tomato',
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
        ]);

        $catalogPlant = CatalogPlant::query()->create([
            'name' => 'Tomato',
            'canonical_name' => 'tomato',
            'plant_type' => PlantType::Vegetable->value,
            'fk_plant_care_id' => $catalogCare->id,
            'description' => 'Reusable catalog tomato',
            'source_provider' => 'local',
            'source_quality' => 'partial',
        ]);

        $legacyPlant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Tomato',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Planted,
            'fk_catalog_plant_id' => $catalogPlant->id,
        ]);

        $careOnlyPlant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Basil',
            'type' => PlantType::Herb,
            'condition' => ConditionType::Planted,
            'fk_catalog_plant_id' => null,
            'fk_plant_care_id' => PlantCare::query()->create([
                'description' => 'Basil care',
                'conditions' => 'Warm and bright',
                'growing_duration_days' => 50,
                'flowering_duration_days' => 10,
                'germinating_duration_days' => 6,
                'mature_duration_days' => 20,
                'mature_duration_end_days' => 10,
                'mature_end_duration_days' => 10,
                'regenerating_duration_days' => 0,
                'reusable' => false,
                'plant_name' => 'Basil',
                'canonical_name' => 'basil',
                'task_type' => 'watering',
                'plant_type' => PlantType::Herb->value,
                'condition' => ConditionType::Planted->value,
                'watering_interval_days' => 3,
                'fertilizing_interval_days' => 21,
                'pest_check_interval_days' => 7,
                'rain_skip_threshold_mm' => 6,
                'frost_temp_threshold_c' => 2,
                'heat_extra_water_temp_c' => 29,
                'wind_protection_kmh' => 35,
                'source_provider' => 'local',
                'source_quality' => 'default',
            ])->id,
        ]);

        $this->artisan('plant-care:repair-shared-links')
            ->assertSuccessful();

        $legacyPlant->refresh();
        $careOnlyPlant->refresh();
        $catalogPlant->refresh();

        $this->assertSame($catalogCare->id, $legacyPlant->fresh('catalogPlant.plantCare')->effectivePlantCare()?->id);

        $this->assertNotNull($careOnlyPlant->fk_catalog_plant_id);
        $this->assertSame(
            CatalogPlant::query()->where('canonical_name', 'basil')->value('id'),
            $careOnlyPlant->fk_catalog_plant_id
        );

        $this->assertSame($catalogCare->id, $catalogPlant->fk_plant_care_id);
    }
}
