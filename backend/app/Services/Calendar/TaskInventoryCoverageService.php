<?php

namespace App\Services\Calendar;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Models\GardenOwner;
use App\Models\InventoryItem;
use App\Models\Task;
use App\Models\TaskResourceRequirement;
use App\ValueObjects\NormalizedTaskResource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class TaskInventoryCoverageService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildPlanningLedger(?GardenOwner $owner): array
    {
        if (! $owner) {
            return [];
        }

        return InventoryItem::query()
            ->where('garden_owner_id', $owner->id)
            ->orderBy('id')
            ->get()
            ->groupBy(function (InventoryItem $item): string {
                return $this->stockKey(
                    mb_strtolower(trim((string) $item->normalized_name ?: (string) $item->name)),
                    $item->inventory_item_type instanceof InventoryItemType ? $item->inventory_item_type : InventoryItemType::from((string) $item->inventory_item_type),
                    $item->unit instanceof InventoryUnit ? $item->unit : InventoryUnit::from((string) $item->unit),
                );
            })
            ->map(function (Collection $items): array {
                /** @var InventoryItem $first */
                $first = $items->first();
                $type = $first->inventory_item_type instanceof InventoryItemType ? $first->inventory_item_type : InventoryItemType::from((string) $first->inventory_item_type);
                $unit = $first->unit instanceof InventoryUnit ? $first->unit : InventoryUnit::from((string) $first->unit);
                $availableQuantity = round((float) $items->sum('quantity'), 2);

                return [
                    'normalized_name' => mb_strtolower(trim((string) ($first->normalized_name ?: $first->name))),
                    'resource_name' => $first->name,
                    'inventory_item_type' => $type->value,
                    'unit' => $unit->value,
                    'available_quantity' => $availableQuantity,
                    'remaining_quantity' => $availableQuantity,
                    'planned_used_quantity' => 0.0,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $ledger
     * @param  array<string, mixed>|TaskResourceRequirement|NormalizedTaskResource  $resource
     * @return array<string, mixed>
     */
    public function reserveRequirementForPlan(array &$ledger, array|TaskResourceRequirement|NormalizedTaskResource $resource): array
    {
        $normalized = $resource instanceof NormalizedTaskResource
            ? $resource
            : NormalizedTaskResource::from($resource);
        $ledgerKey = $this->stockKey($normalized->normalizedName, $normalized->inventoryItemType, $normalized->unit);

        if (! isset($ledger[$ledgerKey])) {
            $ledger[$ledgerKey] = [
                'normalized_name' => $normalized->normalizedName,
                'resource_name' => $normalized->resourceName,
                'inventory_item_type' => $normalized->inventoryItemType->value,
                'unit' => $normalized->unit->value,
                'available_quantity' => 0.0,
                'remaining_quantity' => 0.0,
                'planned_used_quantity' => 0.0,
            ];
        }

        $availableBefore = round((float) $ledger[$ledgerKey]['remaining_quantity'], 2);
        $requiredQuantity = round($normalized->requiredQuantity, 2);
        $reservedQuantity = round(min($availableBefore, $requiredQuantity), 2);
        $shortageQuantity = round(max(0, $requiredQuantity - $reservedQuantity), 2);

        if ($normalized->isConsumable()) {
            $ledger[$ledgerKey]['remaining_quantity'] = round($availableBefore - $reservedQuantity, 2);
            $ledger[$ledgerKey]['planned_used_quantity'] = round(
                (float) $ledger[$ledgerKey]['planned_used_quantity'] + $reservedQuantity,
                2
            );
        }

        return [
            'resource_key' => $normalized->key(),
            'resource_name' => $normalized->resourceName,
            'normalized_name' => $normalized->normalizedName,
            'inventory_item_type' => $normalized->inventoryItemType->value,
            'unit' => $normalized->unit->value,
            'resource_mode' => $normalized->resourceMode,
            'required_quantity' => $requiredQuantity,
            'available_before' => $availableBefore,
            'reserved_quantity' => $reservedQuantity,
            'shortage_quantity' => $shortageQuantity,
            'remaining_after' => round((float) $ledger[$ledgerKey]['remaining_quantity'], 2),
            'is_consumed' => $normalized->isConsumable(),
        ];
    }

    /**
     * @param  iterable<int, Task>  $tasks
     * @return array<string, array<string, mixed>>
     */
    public function summarizeTasksByDate(?GardenOwner $owner, iterable $tasks, bool $lock = false): array
    {
        $taskCollection = collect($tasks)
            ->filter(fn ($task) => $task instanceof Task)
            ->values();

        if ($taskCollection->isEmpty()) {
            return [];
        }

        $taskCollection->each(fn (Task $task) => $task->loadMissing('requiredResources'));

        return $taskCollection
            ->groupBy(fn (Task $task) => $this->taskDateKey($task) ?? 'undated')
            ->map(function (Collection $dateTasks, string $dateKey) use ($owner, $lock): array {
                $plannedTasks = $dateTasks
                    ->filter(fn (Task $task) => $this->isPlannedDemandTask($task))
                    ->values();
                $replenishmentTasks = $dateTasks
                    ->filter(fn (Task $task) => $this->isReplenishmentTask($task) && $this->isPendingTask($task))
                    ->values();
                $resourceSummaries = $this->buildDayRequirementSummaries($owner, $plannedTasks, $replenishmentTasks, $lock);
                $resourceSummariesByKey = collect($resourceSummaries)->keyBy('resource_key');
                $blockedTaskIds = collect($resourceSummaries)
                    ->flatMap(fn (array $summary) => (float) ($summary['shortage_quantity'] ?? 0) > 0 ? ($summary['task_ids'] ?? []) : [])
                    ->unique()
                    ->values();
                $taskContexts = $dateTasks
                    ->mapWithKeys(function (Task $task) use ($resourceSummariesByKey): array {
                        $context = $this->buildTaskInventoryContext($task, $resourceSummariesByKey);

                        return [$task->id => [
                            'inventory_context' => $context,
                            'can_complete' => (bool) ($context['is_actionable'] ?? false),
                        ]];
                    })
                    ->all();
                $plannedTaskCount = $plannedTasks->count();
                $blockedTaskCount = $blockedTaskIds->count();
                $dayInventoryStatus = $blockedTaskCount === 0
                    ? 'fully_covered'
                    : ($plannedTaskCount > 0 && $blockedTaskCount === $plannedTaskCount ? 'blocked' : 'partially_blocked');
                $shortageSummary = collect($resourceSummaries)
                    ->filter(fn (array $summary) => (float) ($summary['shortage_quantity'] ?? 0) > 0)
                    ->map(fn (array $summary): array => [
                        'resource_key' => $summary['resource_key'],
                        'resource_name' => $summary['resource_name'],
                        'inventory_item_type' => $summary['inventory_item_type'],
                        'unit' => $summary['unit'],
                        'resource_mode' => $summary['resource_mode'],
                        'shortage_quantity' => $summary['shortage_quantity'],
                        'blocked_task_count' => count($summary['task_ids'] ?? []),
                        'blocked_task_names' => $summary['task_names'] ?? [],
                    ])
                    ->values()
                    ->all();
                $replenishmentTaskSummaries = $replenishmentTasks
                    ->map(fn (Task $task): array => [
                        'id' => $task->id,
                        'name' => $task->name,
                        'status' => $task->state?->value ?? $task->status,
                        'item' => $task->item,
                        'item_quantity' => $task->item_quantity === null ? null : (float) $task->item_quantity,
                    ])
                    ->values()
                    ->all();

                return [
                    'date' => $dateKey === 'undated' ? null : $dateKey,
                    'day_inventory_status' => $dayInventoryStatus,
                    'blocked_task_count' => $blockedTaskCount,
                    'planned_task_count' => $plannedTaskCount,
                    'grouped_resource_summary' => array_values($resourceSummaries),
                    'shortage_summary' => $shortageSummary,
                    'replenishment_tasks' => $replenishmentTaskSummaries,
                    'summary_text' => $this->buildDaySummaryText($dayInventoryStatus, $blockedTaskCount, $shortageSummary),
                    'tasks' => $taskContexts,
                    // Legacy aliases kept while the UI is migrated.
                    'status' => $blockedTaskCount === 0
                        ? ($resourceSummaries === [] ? 'not_required' : 'available')
                        : 'shortage',
                    'resource_count' => count($resourceSummaries),
                    'shortage_count' => count($shortageSummary),
                    'resources' => array_values($resourceSummaries),
                    'buy_tasks' => $replenishmentTaskSummaries,
                    'buy_task_ids' => collect($replenishmentTaskSummaries)->pluck('id')->values()->all(),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, Task>  $plannedTasks
     * @param  Collection<int, Task>  $replenishmentTasks
     * @return array<int, array<string, mixed>>
     */
    public function buildDayRequirementSummaries(
        ?GardenOwner $owner,
        Collection $plannedTasks,
        Collection $replenishmentTasks,
        bool $lock = false,
    ): array {
        $aggregated = [];

        foreach ($plannedTasks as $task) {
            foreach ($task->requiredResources as $requirement) {
                $normalized = NormalizedTaskResource::from($requirement);
                $resourceKey = $normalized->key();

                if (! isset($aggregated[$resourceKey])) {
                    $aggregated[$resourceKey] = array_merge($normalized->toArray(), [
                        'required_quantity' => 0.0,
                        'shortage_quantity' => 0.0,
                        'available_quantity' => 0.0,
                        'task_ids' => [],
                        'task_names' => [],
                        'replenishment_task_ids' => [],
                    ]);
                }

                $aggregated[$resourceKey]['required_quantity'] = round(
                    (float) $aggregated[$resourceKey]['required_quantity'] + $normalized->requiredQuantity,
                    2
                );
                $aggregated[$resourceKey]['task_ids'][] = $task->id;
                $aggregated[$resourceKey]['task_names'][] = $task->name;
            }
        }

        foreach ($aggregated as $resourceKey => $summary) {
            $items = $owner
                ? $this->matchingItems(
                    $owner,
                    $summary['normalized_name'],
                    InventoryItemType::from($summary['inventory_item_type']),
                    InventoryUnit::from($summary['unit']),
                    $lock,
                )
                : new EloquentCollection();
            $availableQuantity = round((float) $items->sum('quantity'), 2);

            $aggregated[$resourceKey]['available_quantity'] = $availableQuantity;
            $aggregated[$resourceKey]['shortage_quantity'] = round(
                max(0, (float) $summary['required_quantity'] - $availableQuantity),
                2
            );
            $aggregated[$resourceKey]['task_ids'] = array_values(array_unique($aggregated[$resourceKey]['task_ids']));
            $aggregated[$resourceKey]['task_names'] = array_values(array_unique($aggregated[$resourceKey]['task_names']));
            $aggregated[$resourceKey]['is_sufficient'] = (float) $aggregated[$resourceKey]['shortage_quantity'] <= 0;
        }

        foreach ($replenishmentTasks as $task) {
            $task->requiredResources->each(function (TaskResourceRequirement $requirement) use (&$aggregated, $task): void {
                $resourceKey = NormalizedTaskResource::from($requirement)->key();

                if (isset($aggregated[$resourceKey])) {
                    $aggregated[$resourceKey]['replenishment_task_ids'][] = $task->id;
                }
            });
        }

        return collect($aggregated)
            ->map(function (array $summary): array {
                $summary['replenishment_task_ids'] = array_values(array_unique($summary['replenishment_task_ids']));

                return $summary;
            })
            ->sortBy('resource_name')
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $resourceSummariesByKey
     * @return array<string, mixed>
     */
    public function buildTaskInventoryContext(Task $task, Collection $resourceSummariesByKey): array
    {
        if (! $this->isPendingTask($task)) {
            return [
                'status' => $task->state?->value ?? $task->status ?? 'completed',
                'inventory_mode' => 'not_required',
                'is_actionable' => false,
                'shortage_count' => 0,
                'requirements' => [],
                'missing_resources' => [],
                'buy_task_ids' => [],
                'blocked_task_ids' => [],
                'calendar_date' => $this->taskDateKey($task),
            ];
        }

        if ($this->isReplenishmentTask($task)) {
            $buyResource = $task->requiredResources->first();
            $resourceSummary = $buyResource
                ? $resourceSummariesByKey->get(NormalizedTaskResource::from($buyResource)->key())
                : null;
            $blockedTaskIds = $resourceSummary['task_ids'] ?? [];
            $shortageQuantity = round((float) ($resourceSummary['shortage_quantity'] ?? ($task->item_quantity ?? 0)), 2);

            return [
                'status' => 'replenishment',
                'inventory_mode' => 'replenishment',
                'is_actionable' => true,
                'shortage_count' => $shortageQuantity > 0 ? 1 : 0,
                'requirements' => [],
                'missing_resources' => [],
                'buy_task_ids' => [$task->id],
                'blocked_task_ids' => $blockedTaskIds,
                'calendar_date' => $this->taskDateKey($task),
                'replenishment' => [
                    'resource_name' => $task->item,
                    'quantity' => $shortageQuantity,
                    'unit' => data_get($task->inventory_context, 'unit'),
                    'blocked_task_count' => count($blockedTaskIds),
                    'blocked_task_names' => data_get($task->inventory_context, 'planned_for_tasks', []),
                ],
            ];
        }

        if (! $this->isPendingTask($task) || $task->requiredResources->isEmpty()) {
            return [
                'status' => $task->requiredResources->isEmpty() ? 'not_required' : 'available',
                'inventory_mode' => $task->requiredResources->isEmpty() ? 'not_required' : 'available',
                'is_actionable' => true,
                'shortage_count' => 0,
                'requirements' => [],
                'missing_resources' => [],
                'buy_task_ids' => [],
                'blocked_task_ids' => [],
                'calendar_date' => $this->taskDateKey($task),
            ];
        }

        $requirements = $task->requiredResources
            ->map(function (TaskResourceRequirement $requirement) use ($resourceSummariesByKey): array {
                $normalized = NormalizedTaskResource::from($requirement);
                $resourceSummary = $resourceSummariesByKey->get($normalized->key(), []);
                $dailyShortage = round((float) ($resourceSummary['shortage_quantity'] ?? 0), 2);
                $localShortage = $dailyShortage > 0
                    ? round(min($normalized->requiredQuantity, $dailyShortage), 2)
                    : 0.0;

                return [
                    'requirement_id' => $requirement->id,
                    'resource_key' => $normalized->key(),
                    'resource_name' => $normalized->resourceName,
                    'normalized_name' => $normalized->normalizedName,
                    'inventory_item_type' => $normalized->inventoryItemType->value,
                    'unit' => $normalized->unit->value,
                    'resource_mode' => $normalized->resourceMode,
                    'required_quantity' => $normalized->requiredQuantity,
                    'available_quantity' => round((float) ($resourceSummary['available_quantity'] ?? 0), 2),
                    'shortage_quantity' => $localShortage,
                    'daily_required_quantity' => round((float) ($resourceSummary['required_quantity'] ?? $normalized->requiredQuantity), 2),
                    'daily_available_quantity' => round((float) ($resourceSummary['available_quantity'] ?? 0), 2),
                    'daily_shortage_quantity' => $dailyShortage,
                    'is_consumed' => $normalized->isConsumable(),
                    'is_sufficient' => $dailyShortage <= 0,
                    'buy_task_ids' => $resourceSummary['replenishment_task_ids'] ?? [],
                    'blocked_task_ids' => $resourceSummary['task_ids'] ?? [],
                ];
            })
            ->values();
        $missingResources = $requirements
            ->filter(fn (array $requirement) => (float) ($requirement['daily_shortage_quantity'] ?? 0) > 0)
            ->values();

        return [
            'status' => $missingResources->isEmpty() ? 'available' : 'shortage',
            'inventory_mode' => $missingResources->isEmpty() ? 'available' : 'shortage',
            'is_actionable' => $missingResources->isEmpty(),
            'shortage_count' => $missingResources->count(),
            'requirements' => $requirements->all(),
            'missing_resources' => $missingResources->all(),
            'buy_task_ids' => $missingResources
                ->flatMap(fn (array $requirement) => $requirement['buy_task_ids'] ?? [])
                ->unique()
                ->values()
                ->all(),
            'blocked_task_ids' => $missingResources
                ->flatMap(fn (array $requirement) => $requirement['blocked_task_ids'] ?? [])
                ->unique()
                ->values()
                ->all(),
            'calendar_date' => $this->taskDateKey($task),
        ];
    }

    /**
     * @return EloquentCollection<int, InventoryItem>
     */
    public function matchingItems(
        GardenOwner $owner,
        string $normalizedName,
        InventoryItemType $type,
        InventoryUnit $unit,
        bool $lock = false,
    ): EloquentCollection {
        $query = InventoryItem::query()
            ->where('garden_owner_id', $owner->id)
            ->where('normalized_name', $normalizedName)
            ->where('inventory_item_type', $type->value)
            ->where('unit', $unit->value)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function stockKey(string $normalizedName, InventoryItemType $type, InventoryUnit $unit): string
    {
        return implode('|', [$type->value, $unit->value, $normalizedName]);
    }

    private function buildDaySummaryText(string $status, int $blockedTaskCount, array $shortageSummary): string
    {
        if ($status === 'fully_covered') {
            return $shortageSummary === []
                ? 'Inventory is fully covered for planned work on this day.'
                : 'Inventory is fully covered.';
        }

        $resourceList = collect($shortageSummary)
            ->pluck('resource_name')
            ->unique()
            ->values()
            ->all();

        return sprintf(
            '%d planned task%s blocked by %s.',
            $blockedTaskCount,
            $blockedTaskCount === 1 ? ' is' : 's are',
            $resourceList === [] ? 'inventory shortages' : implode(', ', $resourceList)
        );
    }

    private function isPlannedDemandTask(Task $task): bool
    {
        return ! $this->isReplenishmentTask($task) && $this->isPendingTask($task);
    }

    private function isReplenishmentTask(Task $task): bool
    {
        return ($task->task_type ?? $task->type) === 'buy';
    }

    private function isPendingTask(Task $task): bool
    {
        return ($task->state?->value ?? $task->status) === 'pending';
    }

    private function taskDateKey(Task $task): ?string
    {
        $date = $task->date;

        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        if (is_string($date) && $date !== '') {
            return $date;
        }

        return null;
    }
}
