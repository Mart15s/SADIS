<?php

namespace Tests\Feature;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\TaskType;
use App\Models\CatalogPlant;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\Task;
use Database\Seeders\LocalPlantCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class LocalPlantCatalogSeederTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_local_catalog_seeder_resets_existing_planted_and_catalog_data(): void
    {
        [, $owner] = $this->createGardenOwner('catalog-reset@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $calendar = $this->createCalendarForPlot($plot);

        $legacyCare = PlantCare::query()->create([
            'description' => 'Legacy tomato care that should be removed.',
            'conditions' => 'Legacy conditions.',
            'growing_duration_days' => 66,
            'germinating_duration_days' => 10,
            'flowering_duration_days' => 20,
            'mature_duration_days' => 45,
            'mature_duration_end_days' => 20,
            'mature_end_duration_days' => 20,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Legacy Tomato',
            'canonical_name' => 'legacy tomato',
            'task_type' => TaskType::Watering->value,
            'plant_type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 4,
            'fertilizing_interval_days' => 21,
            'pest_check_interval_days' => 7,
            'rain_skip_threshold_mm' => 8.0,
            'frost_temp_threshold_c' => 5.0,
            'heat_extra_water_temp_c' => 29.0,
            'wind_protection_kmh' => 40.0,
            'source_provider' => 'local',
            'source_quality' => 'default',
            'source_common_name' => 'Legacy Tomato',
            'source_scientific_name' => 'Solanum lycopersicum',
            'source_family' => 'Solanaceae',
        ]);

        $orphanCare = PlantCare::query()->create([
            'description' => 'Orphan profile that should also be removed.',
            'conditions' => 'Unused.',
            'growing_duration_days' => 70,
            'germinating_duration_days' => 12,
            'flowering_duration_days' => 0,
            'mature_duration_days' => 60,
            'mature_duration_end_days' => 14,
            'mature_end_duration_days' => 14,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Unused Profile',
            'canonical_name' => 'unused profile',
            'task_type' => TaskType::Watering->value,
            'plant_type' => PlantType::Herb->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 5,
            'fertilizing_interval_days' => 30,
            'pest_check_interval_days' => 10,
            'rain_skip_threshold_mm' => 8.0,
            'frost_temp_threshold_c' => 0.0,
            'heat_extra_water_temp_c' => 30.0,
            'wind_protection_kmh' => 35.0,
            'source_provider' => 'local',
            'source_quality' => 'default',
            'source_common_name' => 'Unused Profile',
        ]);

        $legacyCatalog = CatalogPlant::query()->create([
            'name' => 'Legacy Tomato',
            'canonical_name' => 'legacy tomato',
            'plant_type' => PlantType::Vegetable->value,
            'fk_plant_care_id' => $legacyCare->id,
            'description' => 'Legacy catalog entry.',
            'source_provider' => 'local',
            'source_quality' => 'default',
            'source_scientific_name' => 'Solanum lycopersicum',
            'source_family' => 'Solanaceae',
        ]);

        $plant = Plant::query()->create([
            'name' => 'Legacy Tomato',
            'plant_date' => '2026-04-05',
            'type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Growing->value,
            'fk_plot_id' => $plot->id,
            'plant_zone_id' => $zone->id,
            'fk_plant_zone_id' => $zone->id,
            'fk_catalog_plant_id' => $legacyCatalog->id,
            'fk_plant_care_id' => $legacyCare->id,
        ]);

        $task = Task::query()->create([
            'date' => '2026-04-06',
            'name' => 'Legacy watering',
            'type' => TaskType::Watering->value,
            'task_type' => TaskType::Watering->value,
            'status' => 'pending',
            'state' => 'pending',
            'fk_task_calendar_id' => $calendar->id,
            'task_calendar_id' => $calendar->id,
            'fk_plant_id' => $plant->id,
            'plant_id' => $plant->id,
            'plant_zone_id' => $zone->id,
        ]);

        DB::table('used_on')->insert([
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
            'fk_task_id' => $task->id,
        ]);

        $this->createConditionHistoryForPlant($plant);
        $this->createRotationHistoryForPlot($plot, $zone, $plant);

        DB::table('harvest_records')->insert([
            'plot_id' => $plot->id,
            'plant_id' => $plant->id,
            'task_id' => $task->id,
            'garden_owner_id' => $owner->id,
            'quantity' => 2.5,
            'harvested_on' => '2026-04-07',
            'notes' => 'Legacy harvest',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, Plant::query()->count());
        $this->assertSame(1, CatalogPlant::query()->count());
        $this->assertSame(2, PlantCare::query()->count());

        $this->seed(LocalPlantCatalogSeeder::class);

        $this->assertSame(0, Plant::query()->count());
        $this->assertSame(20, CatalogPlant::query()->count());
        $this->assertSame(20, PlantCare::query()->count());

        $this->assertDatabaseMissing('catalog_plants', ['id' => $legacyCatalog->id]);
        $this->assertDatabaseMissing('plant_care', ['id' => $legacyCare->id]);
        $this->assertDatabaseMissing('plant_care', ['id' => $orphanCare->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('used_on', ['fk_task_id' => $task->id]);
        $this->assertDatabaseMissing('plant_condition_history', ['fk_plant_id' => $plant->id]);
        $this->assertDatabaseMissing('rotation_history', ['fk_plant_id' => $plant->id]);
        $this->assertDatabaseMissing('harvest_records', ['plant_id' => $plant->id]);
    }

    public function test_local_catalog_seeder_creates_expected_classifications_with_linked_care(): void
    {
        $this->seed(LocalPlantCatalogSeeder::class);

        $expectedTypes = [
            'carrot' => PlantType::Vegetable->value,
            'cucumber' => PlantType::Vegetable->value,
            'pea' => PlantType::Legume->value,
            'bean' => PlantType::Legume->value,
            'basil' => PlantType::Herb->value,
            'dill' => PlantType::Herb->value,
            'parsley' => PlantType::Herb->value,
            'mint' => PlantType::Herb->value,
            'strawberry' => PlantType::Berry->value,
            'apple tree' => PlantType::Tree->value,
            'rose' => PlantType::Shrub->value,
        ];

        $this->assertSame(20, CatalogPlant::query()->count());

        foreach ($expectedTypes as $canonicalName => $plantType) {
            $catalogPlant = CatalogPlant::query()
                ->with('plantCare')
                ->where('canonical_name', $canonicalName)
                ->firstOrFail();

            $this->assertSame($plantType, $catalogPlant->plant_type->value ?? $catalogPlant->plant_type);
            $this->assertNotNull($catalogPlant->fk_plant_care_id);
            $this->assertNotNull($catalogPlant->plantCare);
            $this->assertSame($plantType, $catalogPlant->plantCare->plant_type->value ?? $catalogPlant->plantCare->plant_type);
        }

        $this->assertDatabaseHas('catalog_plants', [
            'canonical_name' => 'carrot',
            'plant_type' => PlantType::Vegetable->value,
        ]);
        $this->assertDatabaseMissing('catalog_plants', [
            'canonical_name' => 'carrot',
            'plant_type' => PlantType::Cereal->value,
        ]);
    }

    public function test_local_catalog_seeder_populates_curated_non_placeholder_metadata(): void
    {
        $this->seed(LocalPlantCatalogSeeder::class);

        $catalogPlants = CatalogPlant::query()
            ->with('plantCare')
            ->orderBy('id')
            ->get();

        $this->assertCount(20, $catalogPlants);

        foreach ($catalogPlants as $catalogPlant) {
            $care = $catalogPlant->plantCare;

            $this->assertNotNull($care, $catalogPlant->name.' is missing linked plant care.');
            $this->assertSame('local', $catalogPlant->source_provider);
            $this->assertSame('partial', $catalogPlant->source_quality);
            $this->assertSame('local', $care->source_provider);
            $this->assertSame('partial', $care->source_quality);
            $this->assertNotEmpty($care->source_scientific_name);
            $this->assertNotEmpty($care->source_family);
            $this->assertNotSame('unknown', strtolower((string) $care->source_provider));
            $this->assertNotSame('unknown', strtolower((string) $care->source_quality));
            $this->assertNotSame(strtolower($catalogPlant->name), strtolower(trim((string) $care->description)));
            $this->assertNotSame('not set', strtolower(trim((string) $care->conditions)));
            $this->assertGreaterThanOrEqual(25, strlen((string) $care->description));
            $this->assertGreaterThanOrEqual(20, strlen((string) $care->conditions));
        }

        $tomatoCare = CatalogPlant::query()->where('canonical_name', 'tomato')->firstOrFail()->plantCare()->firstOrFail();
        $appleTreeCare = CatalogPlant::query()->where('canonical_name', 'apple tree')->firstOrFail()->plantCare()->firstOrFail();
        $roseCare = CatalogPlant::query()->where('canonical_name', 'rose')->firstOrFail()->plantCare()->firstOrFail();

        $this->assertFalse((bool) $tomatoCare->reusable);
        $this->assertTrue((bool) $appleTreeCare->reusable);
        $this->assertTrue((bool) $roseCare->reusable);
        $this->assertSame(TaskType::Spray->value, $roseCare->task_type->value ?? $roseCare->task_type);
    }
}
