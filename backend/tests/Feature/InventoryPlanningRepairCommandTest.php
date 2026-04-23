<?php

namespace Tests\Feature;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Models\HasInventory;
use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\TaskResourceRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class InventoryPlanningRepairCommandTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_repair_command_normalizes_known_resources_and_creates_missing_day_level_buy_task(): void
    {
        [, $owner] = $this->createGardenOwner('inventory-repair@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $calendar = $this->createCalendarForPlot($plot, [
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
        ]);

        $inventoryItem = InventoryItem::query()->create([
            'garden_owner_id' => $owner->id,
            'name' => 'Protective cover',
            'normalized_name' => 'protective cover',
            'quantity' => 1,
            'type' => InventoryItemType::Material,
            'inventory_item_type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        HasInventory::query()->create([
            'fk_inventory_item_id' => $inventoryItem->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        foreach (range(1, 3) as $index) {
            $plant = $this->createPlantForPlot($plot, $zone, ['name' => "Repair plant {$index}"]);

            $task = Task::query()->create([
                'date' => '2026-03-20',
                'name' => "Protect plant {$index}",
                'type' => 'rest',
                'task_type' => 'rest',
                'item' => 'Protective cover',
                'item_quantity' => 1,
                'status' => 'pending',
                'state' => 'pending',
                'task_calendar_id' => $calendar->id,
                'fk_task_calendar_id' => $calendar->id,
                'plant_id' => $plant->id,
                'fk_plant_id' => $plant->id,
                'plant_zone_id' => $zone->id,
            ]);

            TaskResourceRequirement::query()->create([
                'task_id' => $task->id,
                'resource_name' => 'Protective cover',
                'normalized_name' => 'protective cover',
                'inventory_item_type' => InventoryItemType::Material,
                'unit' => InventoryUnit::Kilogram,
                'required_quantity' => 1,
                'shortage_quantity' => 0,
                'is_consumed' => true,
            ]);
        }

        $this->artisan('inventory:repair-calendar-resources')
            ->assertSuccessful();

        $inventoryItem->refresh();

        $this->assertSame(InventoryItemType::Tool, $inventoryItem->inventory_item_type);
        $this->assertSame(InventoryUnit::Unit, $inventoryItem->unit);

        $this->assertDatabaseHas('task_resource_requirements', [
            'resource_name' => 'Protective cover',
            'inventory_item_type' => InventoryItemType::Tool->value,
            'unit' => InventoryUnit::Unit->value,
            'is_consumed' => false,
        ]);

        $buyTask = Task::query()
            ->where('task_calendar_id', $calendar->id)
            ->where('type', 'buy')
            ->where('item', 'Protective cover')
            ->firstOrFail();

        $this->assertSame(2.0, (float) $buyTask->item_quantity);
        $this->assertSame('replenishment', $buyTask->inventory_context['status'] ?? null);

        $repairedTask = Task::query()
            ->where('task_calendar_id', $calendar->id)
            ->where('type', 'rest')
            ->firstOrFail();

        $this->assertSame('shortage', $repairedTask->inventory_context['status'] ?? null);
        $this->assertNotEmpty($repairedTask->inventory_context['buy_task_ids'] ?? []);
    }
}
