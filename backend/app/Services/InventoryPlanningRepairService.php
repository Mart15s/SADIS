<?php

namespace App\Services;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Enums\TaskPriority;
use App\Enums\TaskState;
use App\Enums\TaskType;
use App\Models\InventoryItem;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\TaskResourceRequirement;
use Illuminate\Support\Facades\DB;

class InventoryPlanningRepairService
{
    private const KNOWN_RESOURCE_RULES = [
        'fertilizer' => ['type' => InventoryItemType::Material, 'unit' => InventoryUnit::Kilogram, 'is_consumed' => true],
        'fungicide' => ['type' => InventoryItemType::Material, 'unit' => InventoryUnit::Liter, 'is_consumed' => true],
        'sprayer' => ['type' => InventoryItemType::Tool, 'unit' => InventoryUnit::Unit, 'is_consumed' => false],
        'plant support' => ['type' => InventoryItemType::Tool, 'unit' => InventoryUnit::Unit, 'is_consumed' => false],
        'harvest box' => ['type' => InventoryItemType::Tool, 'unit' => InventoryUnit::Unit, 'is_consumed' => false],
        'protective cover' => ['type' => InventoryItemType::Tool, 'unit' => InventoryUnit::Unit, 'is_consumed' => false],
    ];

    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function repair(): array
    {
        return DB::transaction(function (): array {
            $summary = [
                'inventory_items_repaired' => $this->repairInventoryItems(),
                'task_requirements_repaired' => $this->repairTaskRequirements(),
                'task_contexts_refreshed' => 0,
                'buy_tasks_created_or_updated' => 0,
            ];

            foreach (TaskCalendar::query()
                ->with([
                    'plot.gardenOwner',
                    'tasks.requiredResources',
                    'tasks.plant.plantZone',
                    'tasks.plantZone',
                ])
                ->get() as $calendar) {
                $summary['buy_tasks_created_or_updated'] += $this->repairCalendarBuyTasks($calendar);
                $summary['task_contexts_refreshed'] += $this->refreshCalendarInventoryContexts($calendar);
            }

            return $summary;
        });
    }

    private function repairInventoryItems(): int
    {
        $updated = 0;

        foreach (InventoryItem::query()->get() as $item) {
            $normalizedName = mb_strtolower(trim((string) $item->name));
            $rule = self::KNOWN_RESOURCE_RULES[$normalizedName] ?? null;
            $payload = [
                'normalized_name' => $normalizedName,
            ];

            if ($rule) {
                $payload['inventory_item_type'] = $rule['type'];
                $payload['type'] = $rule['type'];
                $payload['unit'] = $rule['unit'];
            }

            if ($this->needsUpdate($item, $payload)) {
                $item->forceFill($payload)->saveQuietly();
                $updated++;
            }
        }

        return $updated;
    }

    private function repairTaskRequirements(): int
    {
        $updated = 0;

        foreach (TaskResourceRequirement::query()->get() as $requirement) {
            $normalizedName = mb_strtolower(trim((string) $requirement->resource_name));
            $rule = self::KNOWN_RESOURCE_RULES[$normalizedName] ?? null;
            $payload = [
                'normalized_name' => $normalizedName,
            ];

            if ($rule) {
                $payload['inventory_item_type'] = $rule['type'];
                $payload['unit'] = $rule['unit'];
                $payload['is_consumed'] = $rule['is_consumed'];
            }

            if ($this->needsUpdate($requirement, $payload)) {
                $requirement->forceFill($payload)->saveQuietly();
                $updated++;
            }
        }

        return $updated;
    }

    private function repairCalendarBuyTasks(TaskCalendar $calendar): int
    {
        $owner = $calendar->plot?->gardenOwner;

        if (! $owner) {
            return 0;
        }

        $calendar->loadMissing([
            'tasks.requiredResources',
            'tasks.plant',
            'tasks.plantZone',
        ]);

        $summaries = $this->inventoryService->summarizeTasksByDate($owner, $calendar->tasks);
        $updated = 0;

        foreach ($summaries as $date => $summary) {
            foreach ($summary['resources'] ?? [] as $resource) {
                if ((float) ($resource['shortage_quantity'] ?? 0) <= 0) {
                    continue;
                }

                $buyTask = $calendar->tasks
                    ->first(function (Task $task) use ($date, $resource): bool {
                        if (($task->task_type ?? $task->type) !== TaskType::Buy->value) {
                            return false;
                        }

                        if (($task->date?->toDateString() ?? $task->date) !== $date) {
                            return false;
                        }

                        $requirement = $task->requiredResources->first();

                        return $requirement
                            && mb_strtolower(trim((string) $requirement->normalized_name)) === (string) $resource['normalized_name']
                            && ($requirement->inventory_item_type?->value ?? $requirement->inventory_item_type) === (string) $resource['inventory_item_type']
                            && ($requirement->unit?->value ?? $requirement->unit) === (string) $resource['unit'];
                    });

                $buyTask = $this->upsertBuyTask($calendar, $date, $resource, $buyTask);

                $calendar->unsetRelation('tasks');
                $calendar->load('tasks.requiredResources');
                $updated++;
            }
        }

        return $updated;
    }

    private function refreshCalendarInventoryContexts(TaskCalendar $calendar): int
    {
        $owner = $calendar->plot?->gardenOwner;

        if (! $owner) {
            return 0;
        }

        $calendar->loadMissing([
            'tasks.requiredResources',
            'tasks.plant.plantZone',
            'tasks.plantZone',
        ]);

        $this->inventoryService->attachLiveTaskInventory($owner, $calendar->tasks);
        $updated = 0;

        foreach ($calendar->tasks as $task) {
            $liveInventoryContext = $task->getAttribute('live_inventory_context');

            if ($liveInventoryContext !== null) {
                Task::query()
                    ->whereKey($task->id)
                    ->update([
                        'inventory_context' => $liveInventoryContext,
                    ]);
                $updated++;
            }

            foreach ($task->requiredResources as $requirement) {
                $liveShortage = $requirement->getAttribute('live_shortage_quantity');

                if ($liveShortage === null) {
                    continue;
                }

                TaskResourceRequirement::query()
                    ->whereKey($requirement->id)
                    ->update([
                        'shortage_quantity' => round((float) $liveShortage, 2),
                    ]);
            }
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function upsertBuyTask(TaskCalendar $calendar, string $date, array $resource, ?Task $buyTask): Task
    {
        $shortageQuantity = round((float) ($resource['shortage_quantity'] ?? 0), 2);
        $requiredQuantity = round((float) ($resource['required_quantity'] ?? $shortageQuantity), 2);
        $taskNames = collect($resource['task_names'] ?? [])->unique()->values()->all();
        $comment = sprintf(
            'Short by %s %s for %s.',
            number_format($shortageQuantity, ($resource['inventory_item_type'] ?? '') === InventoryItemType::Tool->value ? 0 : 2, '.', ''),
            $resource['unit'],
            $taskNames === [] ? 'planned work' : implode(', ', $taskNames),
        );
        $inventoryContext = [
            'status' => 'purchase_required',
            'is_actionable' => true,
            'shortage_count' => 1,
            'shortage_quantity' => $shortageQuantity,
            'required_quantity' => $requiredQuantity,
            'available_quantity' => round((float) ($resource['available_quantity'] ?? 0), 2),
            'planned_for_tasks' => $taskNames,
            'planned_for_task_count' => count($taskNames),
            'calendar_date' => $date,
            'expected_item_type' => $resource['inventory_item_type'],
            'unit' => $resource['unit'],
            'buy_task_ids' => $buyTask ? [$buyTask->id] : [],
        ];

        if (! $buyTask) {
            $buyTask = Task::query()->create([
                'date' => $date,
                'name' => "Buy {$resource['resource_name']}",
                'task_type' => TaskType::Buy->value,
                'type' => TaskType::Buy->value,
                'priority' => TaskPriority::High->value,
                'reason' => sprintf(
                    'Day-level inventory shortage blocks %d planned task(s) on %s.',
                    count($taskNames),
                    $date,
                ),
                'comment' => $comment,
                'item' => $resource['resource_name'],
                'item_quantity' => $shortageQuantity,
                'inventory_context' => $inventoryContext,
                'state' => TaskState::Pending,
                'status' => TaskState::Pending->value,
                'task_calendar_id' => $calendar->id,
                'fk_task_calendar_id' => $calendar->id,
                'plant_id' => null,
                'fk_plant_id' => null,
                'plant_zone_id' => null,
            ]);
        } else {
            $buyTask->forceFill([
                'name' => "Buy {$resource['resource_name']}",
                'priority' => TaskPriority::High->value,
                'reason' => sprintf(
                    'Day-level inventory shortage blocks %d planned task(s) on %s.',
                    count($taskNames),
                    $date,
                ),
                'comment' => $comment,
                'item' => $resource['resource_name'],
                'item_quantity' => $shortageQuantity,
                'inventory_context' => array_merge($inventoryContext, ['buy_task_ids' => [$buyTask->id]]),
            ])->saveQuietly();
        }

        $requirement = $buyTask->requiredResources()->first();

        if (! $requirement) {
            TaskResourceRequirement::query()->create([
                'task_id' => $buyTask->id,
                'resource_name' => $resource['resource_name'],
                'normalized_name' => $resource['normalized_name'],
                'inventory_item_type' => $resource['inventory_item_type'],
                'unit' => $resource['unit'],
                'required_quantity' => $shortageQuantity,
                'shortage_quantity' => $shortageQuantity,
                'is_consumed' => false,
            ]);
        } else {
            $requirement->forceFill([
                'resource_name' => $resource['resource_name'],
                'normalized_name' => $resource['normalized_name'],
                'inventory_item_type' => $resource['inventory_item_type'],
                'unit' => $resource['unit'],
                'required_quantity' => $shortageQuantity,
                'shortage_quantity' => $shortageQuantity,
                'is_consumed' => false,
            ])->saveQuietly();
        }

        return $buyTask->fresh(['requiredResources']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function needsUpdate(object $model, array $payload): bool
    {
        foreach ($payload as $attribute => $value) {
            $current = $model->{$attribute};
            $currentValue = $current instanceof \BackedEnum ? $current->value : $current;
            $nextValue = $value instanceof \BackedEnum ? $value->value : $value;

            if ((string) $currentValue !== (string) $nextValue) {
                return true;
            }
        }

        return false;
    }
}
