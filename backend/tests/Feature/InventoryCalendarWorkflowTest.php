<?php

namespace Tests\Feature;

use App\Enums\ConditionType;
use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Enums\PlantType;
use App\Models\GardenOwner;
use App\Models\HasInventory;
use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\TaskResourceRequirement;
use App\Services\InventoryService;
use App\Services\TaskCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class InventoryCalendarWorkflowTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-20 09:00:00');
        config([
            'services.meteo_lt.base_url' => 'https://api.meteo.lt/v1',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_material_requirement_with_sufficient_inventory_does_not_create_buy_task(): void
    {
        [, $owner, $plot] = $this->createPlotContext();
        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 1,
            'pest_check_interval_days' => 99,
            'germinating_duration_days' => 0,
        ]);
        $plant = $this->createPlantForPlot($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->createInventoryItemForOwner($owner, [
            'name' => 'Fertilizer',
            'quantity' => 2,
            'type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 9, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 6],
        ]);

        $calendar = $this->generateCalendar($plot);

        $this->assertDatabaseHas('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'fertilize',
            'fk_plant_id' => $plant->id,
        ]);

        $this->assertDatabaseMissing('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'buy',
            'item' => 'Fertilizer',
        ]);
    }

    public function test_material_requirement_with_shortage_creates_buy_task(): void
    {
        [, , $plot] = $this->createPlotContext();
        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 1,
            'pest_check_interval_days' => 99,
            'germinating_duration_days' => 0,
        ]);

        $this->createPlantForPlot($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 9, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 6],
        ]);

        $calendar = $this->generateCalendar($plot);

        $this->assertDatabaseHas('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'buy',
            'item' => 'Fertilizer',
        ]);
    }

    public function test_reusable_tool_with_inventory_does_not_create_buy_task(): void
    {
        [, $owner, $plot] = $this->createPlotContext();
        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'wind_protection_kmh' => 20,
        ]);

        $this->createPlantForPlot($plot, $zone, [
            'fk_plant_care_id' => $care->id,
        ]);

        $this->createInventoryItemForOwner($owner, [
            'name' => 'Plant support',
            'quantity' => 1,
            'type' => InventoryItemType::Tool,
            'unit' => InventoryUnit::Unit,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 8, 'temp_max' => 15, 'rain' => 0, 'wind_kmh' => 31],
        ]);

        $calendar = $this->generateCalendar($plot);

        $this->assertDatabaseMissing('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'buy',
            'item' => 'Plant support',
        ]);
    }

    public function test_missing_reusable_tool_creates_buy_task(): void
    {
        [, , $plot] = $this->createPlotContext();
        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'wind_protection_kmh' => 20,
        ]);

        $this->createPlantForPlot($plot, $zone, [
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 8, 'temp_max' => 15, 'rain' => 0, 'wind_kmh' => 31],
        ]);

        $calendar = $this->generateCalendar($plot);

        $this->assertDatabaseHas('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'buy',
            'item' => 'Plant support',
        ]);
    }

    public function test_day_level_aggregation_creates_single_buy_task_for_shared_reusable_shortage(): void
    {
        [$user, $owner, $plot] = $this->createPlotContext();
        Sanctum::actingAs($user);

        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'frost_temp_threshold_c' => 2,
        ]);

        foreach (range(1, 3) as $index) {
            $this->createPlantForPlot($plot, $zone, [
                'name' => "Bean {$index}",
                'plant_date' => '2026-03-19',
                'fk_plant_care_id' => $care->id,
            ]);
        }

        $this->createInventoryItemForOwner($owner, [
            'name' => 'Protective cover',
            'quantity' => 1,
            'type' => InventoryItemType::Tool,
            'unit' => InventoryUnit::Unit,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => -3, 'temp_max' => 4, 'rain' => 0, 'wind_kmh' => 5],
        ]);

        $calendar = $this->generateCalendar($plot);
        $calendar->load(['tasks.requiredResources', 'plot.gardenOwner']);

        $daySummary = app(InventoryService::class)->summarizeTasksByDate($owner, $calendar->tasks)['2026-03-20'];
        $protectiveCoverSummary = collect($daySummary['resources'])
            ->firstWhere('normalized_name', 'protective cover');

        $this->assertNotNull($protectiveCoverSummary);
        $this->assertSame(3.0, $protectiveCoverSummary['required_quantity']);
        $this->assertSame(1.0, $protectiveCoverSummary['available_quantity']);
        $this->assertSame(2.0, $protectiveCoverSummary['shortage_quantity']);

        $buyTasks = Task::query()
            ->where('fk_task_calendar_id', $calendar->id)
            ->where('type', 'buy')
            ->where('item', 'Protective cover')
            ->get();

        $this->assertCount(1, $buyTasks);
        $this->assertSame(2.0, (float) $buyTasks->first()->item_quantity);

        $response = $this->getJson("/api/calendars/{$calendar->id}/tasks?date=2026-03-20");

        $response->assertOk();

        collect($response->json('data'))
            ->filter(fn (array $task) => $task['type'] === 'rest' && ! empty($task['required_resources']))
            ->each(function (array $task): void {
                $this->assertFalse($task['can_complete']);
                $this->assertEquals(3.0, $task['required_resources'][0]['daily_required_quantity']);
                $this->assertEquals(2.0, $task['required_resources'][0]['daily_shortage_quantity']);
            });
    }

    public function test_completing_task_with_consumable_material_reduces_inventory(): void
    {
        [$user, $owner, $plot] = $this->createPlotContext();
        Sanctum::actingAs($user);

        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        [$task] = $this->createTaskWithRequirement($plot, $plant, [
            'resource_name' => 'Fertilizer',
            'inventory_item_type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
            'required_quantity' => 2,
            'is_consumed' => true,
        ]);

        $inventoryItem = $this->createInventoryItemForOwner($owner, [
            'name' => 'Fertilizer',
            'quantity' => 5,
            'type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        $this->patchJson("/api/tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('task.status', 'completed');

        $this->assertDatabaseHas('inventory_items', [
            'id' => $inventoryItem->id,
            'quantity' => 3,
        ]);
    }

    public function test_completing_task_with_reusable_tool_does_not_reduce_inventory(): void
    {
        [$user, $owner, $plot] = $this->createPlotContext();
        Sanctum::actingAs($user);

        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        [$task] = $this->createTaskWithRequirement($plot, $plant, [
            'resource_name' => 'Plant support',
            'inventory_item_type' => InventoryItemType::Tool,
            'unit' => InventoryUnit::Unit,
            'required_quantity' => 1,
            'is_consumed' => false,
            'type' => 'rest',
        ]);

        $inventoryItem = $this->createInventoryItemForOwner($owner, [
            'name' => 'Plant support',
            'quantity' => 1,
            'type' => InventoryItemType::Tool,
            'unit' => InventoryUnit::Unit,
        ]);

        $this->patchJson("/api/tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('task.status', 'completed');

        $this->assertDatabaseHas('inventory_items', [
            'id' => $inventoryItem->id,
            'quantity' => 1,
        ]);
    }

    public function test_completion_with_insufficient_inventory_fails_without_negative_balance(): void
    {
        [$user, $owner, $plot] = $this->createPlotContext();
        Sanctum::actingAs($user);

        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        [$task] = $this->createTaskWithRequirement($plot, $plant, [
            'resource_name' => 'Fertilizer',
            'inventory_item_type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
            'required_quantity' => 3,
            'is_consumed' => true,
        ]);

        $inventoryItem = $this->createInventoryItemForOwner($owner, [
            'name' => 'Fertilizer',
            'quantity' => 1,
            'type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        $this->patchJson("/api/tasks/{$task->id}/complete")
            ->assertStatus(422);

        $this->assertDatabaseHas('inventory_items', [
            'id' => $inventoryItem->id,
            'quantity' => 1,
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'pending',
        ]);
    }

    public function test_completion_with_insufficient_reusable_resource_is_blocked(): void
    {
        [$user, $owner, $plot] = $this->createPlotContext();
        Sanctum::actingAs($user);

        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        [$task] = $this->createTaskWithRequirement($plot, $plant, [
            'resource_name' => 'Protective cover',
            'inventory_item_type' => InventoryItemType::Tool,
            'unit' => InventoryUnit::Unit,
            'required_quantity' => 2,
            'is_consumed' => false,
            'type' => 'rest',
        ]);

        $inventoryItem = $this->createInventoryItemForOwner($owner, [
            'name' => 'Protective cover',
            'quantity' => 1,
            'type' => InventoryItemType::Tool,
            'unit' => InventoryUnit::Unit,
        ]);

        $this->patchJson("/api/tasks/{$task->id}/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['task', 'missing_resources']);

        $this->assertDatabaseHas('inventory_items', [
            'id' => $inventoryItem->id,
            'quantity' => 1,
        ]);
    }

    public function test_reusable_resource_is_not_treated_as_single_shared_unit_for_multiple_same_day_tasks(): void
    {
        [$user, $owner, $plot] = $this->createPlotContext();
        Sanctum::actingAs($user);

        $calendar = $this->createCalendarForPlot($plot, [
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
        ]);
        $zone = $this->createZoneForPlot($plot);
        $tasks = [];

        foreach (range(1, 3) as $index) {
            $plant = $this->createPlantForPlot($plot, $zone, ['name' => "Cover plant {$index}"]);
            $tasks[] = $this->createTaskForExistingCalendarWithRequirement($calendar, $plant, [
                'name' => "Protect plant {$index}",
                'type' => 'rest',
                'resource_name' => 'Protective cover',
                'inventory_item_type' => InventoryItemType::Tool,
                'unit' => InventoryUnit::Unit,
                'required_quantity' => 1,
                'is_consumed' => false,
            ])[0];
        }

        $this->createInventoryItemForOwner($owner, [
            'name' => 'Protective cover',
            'quantity' => 2,
            'type' => InventoryItemType::Tool,
            'unit' => InventoryUnit::Unit,
        ]);

        $response = $this->getJson("/api/calendars/{$calendar->id}/tasks?date=2026-03-20");

        $response->assertOk();

        collect($response->json('data'))
            ->where('type', 'rest')
            ->each(function (array $task): void {
                $this->assertFalse($task['can_complete']);
                $this->assertEquals(3.0, $task['required_resources'][0]['daily_required_quantity']);
                $this->assertEquals(1.0, $task['required_resources'][0]['daily_shortage_quantity']);
            });

        $this->patchJson("/api/tasks/{$tasks[0]->id}/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['task', 'missing_resources']);
    }

    public function test_task_listing_returns_live_inventory_shortage_details(): void
    {
        [$user, $owner, $plot] = $this->createPlotContext();
        Sanctum::actingAs($user);

        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        [$task] = $this->createTaskWithRequirement($plot, $plant, [
            'resource_name' => 'Fertilizer',
            'inventory_item_type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
            'required_quantity' => 2,
            'is_consumed' => true,
        ]);

        $this->createInventoryItemForOwner($owner, [
            'name' => 'Fertilizer',
            'quantity' => 0.5,
            'type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        $response = $this->getJson("/api/calendars/{$task->fk_task_calendar_id}/tasks?date=2026-03-20");

        $response->assertOk()
            ->assertJsonPath('data.0.id', $task->id)
            ->assertJsonPath('data.0.can_complete', false)
            ->assertJsonPath('data.0.inventory_context.status', 'shortage')
            ->assertJsonPath('data.0.required_resources.0.available_quantity', 0.5)
            ->assertJsonPath('data.0.required_resources.0.shortage_quantity', 1.5)
            ->assertJsonPath('data.0.required_resources.0.is_shortage', true);
    }

    public function test_tasks_become_actionable_after_inventory_is_replenished(): void
    {
        [$user, $owner, $plot] = $this->createPlotContext();
        Sanctum::actingAs($user);

        $calendar = $this->createCalendarForPlot($plot, [
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
        ]);
        $zone = $this->createZoneForPlot($plot);

        foreach (range(1, 2) as $index) {
            $plant = $this->createPlantForPlot($plot, $zone, ['name' => "Fertilize plant {$index}"]);
            $this->createTaskForExistingCalendarWithRequirement($calendar, $plant, [
                'name' => "Feed plant {$index}",
                'type' => 'fertilize',
                'resource_name' => 'Fertilizer',
                'inventory_item_type' => InventoryItemType::Material,
                'unit' => InventoryUnit::Kilogram,
                'required_quantity' => 1,
                'is_consumed' => true,
            ]);
        }

        $this->createInventoryItemForOwner($owner, [
            'name' => 'Fertilizer',
            'quantity' => 1,
            'type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        $blockedResponse = $this->getJson("/api/calendars/{$calendar->id}/tasks?date=2026-03-20");
        $blockedResponse->assertOk()
            ->assertJsonPath('data.0.can_complete', false);

        $this->createInventoryItemForOwner($owner, [
            'name' => 'Fertilizer',
            'quantity' => 1,
            'type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        $readyResponse = $this->getJson("/api/calendars/{$calendar->id}/tasks?date=2026-03-20");
        $readyResponse->assertOk()
            ->assertJsonPath('data.0.can_complete', true)
            ->assertJsonPath('data.1.can_complete', true);
    }

    public function test_second_completion_attempt_does_not_deduct_inventory_twice(): void
    {
        [$user, $owner, $plot] = $this->createPlotContext();
        Sanctum::actingAs($user);

        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        [$task] = $this->createTaskWithRequirement($plot, $plant, [
            'resource_name' => 'Fertilizer',
            'inventory_item_type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
            'required_quantity' => 2,
            'is_consumed' => true,
        ]);

        $inventoryItem = $this->createInventoryItemForOwner($owner, [
            'name' => 'Fertilizer',
            'quantity' => 5,
            'type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        $this->patchJson("/api/tasks/{$task->id}/complete")
            ->assertOk();

        $this->patchJson("/api/tasks/{$task->id}/complete")
            ->assertStatus(422);

        $this->assertDatabaseHas('inventory_items', [
            'id' => $inventoryItem->id,
            'quantity' => 3,
        ]);
    }

    public function test_existing_pending_buy_task_is_not_duplicated(): void
    {
        [, , $plot] = $this->createPlotContext();
        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 1,
            'pest_check_interval_days' => 99,
            'germinating_duration_days' => 0,
        ]);

        $this->createPlantForPlot($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        [$existingBuyTask] = $this->createTaskWithRequirement($plot, null, [
            'resource_name' => 'Fertilizer',
            'inventory_item_type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
            'required_quantity' => 1,
            'shortage_quantity' => 1,
            'is_consumed' => false,
            'type' => 'buy',
            'name' => 'Buy Fertilizer',
        ], '2026-03-19');

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 9, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 6],
        ]);

        $this->generateCalendar($plot);

        $this->assertSame(
            1,
            Task::query()
                ->where('type', 'buy')
                ->whereHas('taskCalendar', fn ($query) => $query->where('plot_id', $plot->id))
                ->count()
        );

        $this->assertDatabaseHas('tasks', [
            'id' => $existingBuyTask->id,
            'status' => 'pending',
        ]);
    }

    public function test_buy_task_comment_does_not_repeat_for_identical_missing_actions(): void
    {
        [, , $plot] = $this->createPlotContext();
        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'frost_temp_threshold_c' => 2,
        ]);

        $this->createPlantForPlot($plot, $zone, [
            'name' => 'Bean',
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);
        $this->createPlantForPlot($plot, $zone, [
            'name' => 'Bean',
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => -2, 'temp_max' => 5, 'rain' => 0, 'wind_kmh' => 4],
        ]);

        $calendar = $this->generateCalendar($plot);

        $buyTask = Task::query()
            ->where('fk_task_calendar_id', $calendar->id)
            ->where('type', 'buy')
            ->where('item', 'Protective cover')
            ->firstOrFail();

        $this->assertSame(
            'Missing 2 unit for 1 blocked task.',
            $buyTask->comment
        );
    }

    private function createPlotContext(): array
    {
        [$user, $owner] = $this->createGardenOwner('workflow-owner@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'name' => 'Workflow plot',
            'city' => 'Vilnius',
            'share' => false,
        ]);

        return [$user, $owner, $plot];
    }

    private function createPlantCare(array $overrides = []): PlantCare
    {
        return PlantCare::query()->create(array_merge([
            'description' => 'Workflow plant care',
            'conditions' => 'Sauleta',
            'germinating_duration_days' => 1,
            'growing_duration_days' => 3,
            'flowering_duration_days' => 1,
            'mature_duration_days' => 2,
            'mature_duration_end_days' => 1,
            'mature_end_duration_days' => 1,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Pomidoras',
            'canonical_name' => 'pomidoras',
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
        ], $overrides));
    }

    private function createInventoryItemForOwner(GardenOwner $owner, array $overrides = []): InventoryItem
    {
        $name = (string) ($overrides['name'] ?? 'Fertilizer');
        $type = $overrides['inventory_item_type'] ?? $overrides['type'] ?? InventoryItemType::Material;
        $normalizedType = $type instanceof InventoryItemType ? $type : InventoryItemType::from((string) $type);
        $unit = $overrides['unit'] ?? ($normalizedType === InventoryItemType::Tool ? InventoryUnit::Unit : InventoryUnit::Kilogram);

        $item = InventoryItem::query()->create(array_merge([
            'garden_owner_id' => $owner->id,
            'name' => $name,
            'normalized_name' => mb_strtolower(trim($name)),
            'quantity' => 10,
            'type' => $normalizedType,
            'inventory_item_type' => $normalizedType,
            'unit' => $unit,
        ], $overrides));

        HasInventory::query()->create([
            'fk_inventory_item_id' => $item->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $requirement
     * @return array{0: Task, 1: TaskResourceRequirement}
     */
    private function createTaskWithRequirement(Plot $plot, ?Plant $plant, array $requirement, string $date = '2026-03-20'): array
    {
        $calendar = TaskCalendar::query()->create([
            'creation_date' => now(),
            'start_date' => $date,
            'end_date' => $date,
            'fk_plot_id' => $plot->id,
        ]);

        $task = Task::query()->create([
            'date' => $date,
            'name' => $requirement['name'] ?? 'Test workflow task',
            'type' => $requirement['type'] ?? 'fertilize',
            'task_type' => $requirement['type'] ?? 'fertilize',
            'item' => $requirement['resource_name'],
            'item_quantity' => $requirement['required_quantity'],
            'status' => 'pending',
            'state' => 'pending',
            'fk_task_calendar_id' => $calendar->id,
            'fk_plant_id' => $plant?->id,
        ]);

        $taskRequirement = TaskResourceRequirement::query()->create([
            'task_id' => $task->id,
            'resource_name' => $requirement['resource_name'],
            'normalized_name' => mb_strtolower(trim((string) $requirement['resource_name'])),
            'inventory_item_type' => $requirement['inventory_item_type'],
            'unit' => $requirement['unit'],
            'required_quantity' => $requirement['required_quantity'],
            'shortage_quantity' => $requirement['shortage_quantity'] ?? 0,
            'is_consumed' => $requirement['is_consumed'],
        ]);

        return [$task, $taskRequirement];
    }

    /**
     * @param  array<string, mixed>  $requirement
     * @return array{0: Task, 1: TaskResourceRequirement}
     */
    private function createTaskForExistingCalendarWithRequirement(TaskCalendar $calendar, ?Plant $plant, array $requirement): array
    {
        $task = Task::query()->create([
            'date' => $calendar->start_date->toDateString(),
            'name' => $requirement['name'] ?? 'Test workflow task',
            'type' => $requirement['type'] ?? 'fertilize',
            'task_type' => $requirement['type'] ?? 'fertilize',
            'item' => $requirement['resource_name'],
            'item_quantity' => $requirement['required_quantity'],
            'status' => 'pending',
            'state' => 'pending',
            'task_calendar_id' => $calendar->id,
            'fk_task_calendar_id' => $calendar->id,
            'plant_id' => $plant?->id,
            'fk_plant_id' => $plant?->id,
            'plant_zone_id' => $plant?->plant_zone_id ?? $plant?->fk_plant_zone_id,
        ]);

        $taskRequirement = TaskResourceRequirement::query()->create([
            'task_id' => $task->id,
            'resource_name' => $requirement['resource_name'],
            'normalized_name' => mb_strtolower(trim((string) $requirement['resource_name'])),
            'inventory_item_type' => $requirement['inventory_item_type'],
            'unit' => $requirement['unit'],
            'required_quantity' => $requirement['required_quantity'],
            'shortage_quantity' => $requirement['shortage_quantity'] ?? 0,
            'is_consumed' => $requirement['is_consumed'],
        ]);

        return [$task, $taskRequirement];
    }

    private function generateCalendar(Plot $plot): TaskCalendar
    {
        return app(TaskCalendarService::class)->generate(
            $plot->fresh(),
            Carbon::parse('2026-03-20')->startOfDay(),
            Carbon::parse('2026-03-20')->startOfDay(),
        );
    }

    private function fakeWeather(array $forecastDays): void
    {
        Http::fake(function ($request) use ($forecastDays) {
            $url = $request->url();

            if (str_ends_with($url, '/places')) {
                return Http::response([
                    [
                        'code' => 'vilnius',
                        'name' => 'Vilnius',
                        'administrativeDivision' => 'Vilniaus miesto savivaldybe',
                        'countryCode' => 'LT',
                        'coordinates' => [
                            'latitude' => 54.6872,
                            'longitude' => 25.2797,
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, '/forecasts/long-term')) {
                return Http::response([
                    'place' => [
                        'code' => 'vilnius',
                        'name' => 'Vilnius',
                        'administrativeDivision' => 'Vilniaus miesto savivaldybe',
                        'country' => 'Lietuva',
                        'countryCode' => 'LT',
                        'coordinates' => [
                            'latitude' => 54.6872,
                            'longitude' => 25.2797,
                        ],
                    ],
                    'forecastType' => 'long-term',
                    'forecastCreationTimeUtc' => '2026-03-20 09:00:00',
                    'forecastTimestamps' => collect($forecastDays)->flatMap(function (array $day) {
                        $timestamp = Carbon::parse($day['date']);
                        $humidity = $day['humidity'] ?? 70;
                        $windSpeed = round(($day['wind_kmh'] ?? 0) / 3.6, 3);

                        return [
                            [
                                'forecastTimeUtc' => $timestamp->copy()->setTime(6, 0)->toDateTimeString(),
                                'airTemperature' => $day['temp_min'],
                                'relativeHumidity' => $humidity,
                                'totalPrecipitation' => 0,
                                'windSpeed' => $windSpeed,
                                'conditionCode' => 'clear',
                            ],
                            [
                                'forecastTimeUtc' => $timestamp->copy()->setTime(12, 0)->toDateTimeString(),
                                'airTemperature' => round(($day['temp_min'] + $day['temp_max']) / 2, 2),
                                'relativeHumidity' => $humidity,
                                'totalPrecipitation' => $day['rain'] ?? 0,
                                'windSpeed' => $windSpeed,
                                'conditionCode' => 'clear',
                            ],
                            [
                                'forecastTimeUtc' => $timestamp->copy()->setTime(18, 0)->toDateTimeString(),
                                'airTemperature' => $day['temp_max'],
                                'relativeHumidity' => $humidity,
                                'totalPrecipitation' => 0,
                                'windSpeed' => $windSpeed,
                                'conditionCode' => 'clear',
                            ],
                        ];
                    })->values()->all(),
                ], 200);
            }

            throw new \RuntimeException("Unexpected HTTP request [{$url}]");
        });
    }
}
