<?php

namespace App\Services;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Models\GardenOwner;
use App\Models\InventoryItem;
use App\Models\InventoryUsageLog;
use App\Models\Task;
use App\Models\TaskResourceRequirement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * @return EloquentCollection<int, InventoryItem>
     */
    public function listForOwner(GardenOwner $owner): EloquentCollection
    {
        return $this->queryForOwner($owner)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function getForOwner(GardenOwner $owner, InventoryItem $item): InventoryItem
    {
        return $this->queryForOwner($owner)
            ->whereKey($item->getKey())
            ->firstOrFail();
    }

    public function createForOwner(GardenOwner $owner, array $data): InventoryItem
    {
        return DB::transaction(function () use ($owner, $data) {
            $payload = $this->prepareInventoryPayload($data);

            $item = InventoryItem::query()->create(array_merge($payload, [
                'garden_owner_id' => $owner->id,
            ]));

            return $item->fresh();
        });
    }

    public function updateForOwner(GardenOwner $owner, InventoryItem $item, array $data): InventoryItem
    {
        $item = $this->getForOwner($owner, $item);
        $item->update($this->prepareInventoryPayload($data, $item));

        return $item->fresh();
    }

    public function deleteForOwner(GardenOwner $owner, InventoryItem $item): void
    {
        $item = $this->getForOwner($owner, $item);
        $item->delete();
    }

    public function deductMaterialForOwner(GardenOwner $owner, string $name, float $quantity): InventoryItem
    {
        $normalizedName = Str::of($name)->trim()->lower()->value();

        if ($normalizedName === '' || $quantity <= 0) {
            throw ValidationException::withMessages([
                'materials_used' => ['Panaudotu medziagu duomenys yra neteisingi.'],
            ]);
        }

        $item = $this->queryForOwner($owner)
            ->where('inventory_item_type', InventoryItemType::Material->value)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw ValidationException::withMessages([
                'materials_used' => ["Inventoriaus elementas {$name} nerastas."],
            ]);
        }

        $remaining = round((float) $item->quantity - $quantity, 2);

        if ($remaining < 0) {
            throw ValidationException::withMessages([
                'materials_used' => ["Nepakanka inventoriaus elemento {$name} kiekio."],
            ]);
        }

        $item->update([
            'quantity' => $remaining,
        ]);

        return $item->fresh();
    }

    public function checkIfEnough(GardenOwner $owner, string $name, float $quantity): bool
    {
        $normalizedName = Str::of($name)->trim()->lower()->value();

        if ($normalizedName === '' || $quantity <= 0) {
            return false;
        }

        $item = $this->queryForOwner($owner)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
            ->first();

        if (! $item) {
            return false;
        }

        return (float) $item->quantity >= $quantity;
    }

    /**
     * @return array<string, mixed>
     */
    public function describeTaskInventory(?GardenOwner $owner, Task $task): array
    {
        $task->loadMissing(['requiredResources', 'taskCalendar.tasks.requiredResources']);

        $dayTasks = $task->taskCalendar?->relationLoaded('tasks')
            ? $task->taskCalendar->tasks->filter(fn (Task $calendarTask) => $this->taskDateKey($calendarTask) === $this->taskDateKey($task))
            : null;

        if ($dayTasks instanceof \Illuminate\Support\Collection && $dayTasks->isNotEmpty()) {
            $summariesByDate = $this->buildDateInventorySummaries($owner, $dayTasks, false);

            return $summariesByDate[$this->taskDateKey($task)]['tasks'][$task->id]['inventory_context']
                ?? [
                    'status' => 'not_required',
                    'is_actionable' => true,
                    'shortage_count' => 0,
                    'requirements' => [],
                    'missing_resources' => [],
                ];
        }

        $snapshots = $this->prepareTaskRequirementSnapshots($owner, $task, false);

        return $this->buildTaskInventoryContext($snapshots);
    }

    /**
     * @param  iterable<int, Task>  $tasks
     */
    public function attachLiveTaskInventory(?GardenOwner $owner, iterable $tasks): array
    {
        $summariesByDate = $this->buildDateInventorySummaries($owner, $tasks, false);

        foreach ($tasks as $task) {
            $dateKey = $this->taskDateKey($task);
            $taskSummary = $summariesByDate[$dateKey]['tasks'][$task->id] ?? null;
            $context = $taskSummary['inventory_context'] ?? [
                'status' => 'not_required',
                'is_actionable' => true,
                'shortage_count' => 0,
                'requirements' => [],
                'missing_resources' => [],
            ];
            $detailsByRequirementId = collect($context['requirements'] ?? [])
                ->keyBy('requirement_id');

            $task->setAttribute('live_inventory_context', $context);
            $task->setAttribute('can_complete_now', (bool) ($taskSummary['can_complete'] ?? ($context['is_actionable'] ?? false)));

            $task->requiredResources->each(function (TaskResourceRequirement $requirement) use ($detailsByRequirementId): void {
                $detail = $detailsByRequirementId->get($requirement->id);

                if (! $detail) {
                    return;
                }

                $requirement->setAttribute('available_quantity', $detail['available_quantity']);
                $requirement->setAttribute('live_shortage_quantity', $detail['shortage_quantity']);
                $requirement->setAttribute('is_sufficient', $detail['is_sufficient']);
                $requirement->setAttribute('daily_required_quantity', $detail['daily_required_quantity'] ?? null);
                $requirement->setAttribute('daily_available_quantity', $detail['daily_available_quantity'] ?? null);
                $requirement->setAttribute('daily_shortage_quantity', $detail['daily_shortage_quantity'] ?? null);
                $requirement->setAttribute('buy_task_ids', $detail['buy_task_ids'] ?? []);
            });
        }

        return collect($summariesByDate)
            ->map(function (array $summary): array {
                unset($summary['tasks']);

                return $summary;
            })
            ->all();
    }

    /**
     * @param  iterable<int, Task>  $tasks
     * @return array<string, array<string, mixed>>
     */
    public function summarizeTasksByDate(?GardenOwner $owner, iterable $tasks): array
    {
        return collect($this->buildDateInventorySummaries($owner, $tasks, false))
            ->map(function (array $summary): array {
                unset($summary['tasks']);

                return $summary;
            })
            ->all();
    }

    /**
     * @param  iterable<int, Task>|null  $dayTasks
     * @return array<string, mixed>
     */
    public function assertTaskCanBeCompletedForDay(GardenOwner $owner, Task $task, ?iterable $dayTasks = null): array
    {
        $dateKey = $this->taskDateKey($task);

        if ($dateKey === null) {
            return [
                'status' => 'not_required',
                'is_actionable' => true,
                'shortage_count' => 0,
                'requirements' => [],
                'missing_resources' => [],
            ];
        }

        $taskCollection = collect($dayTasks ?? [$task])
            ->filter(fn ($candidate) => $candidate instanceof Task)
            ->values();

        if ($taskCollection->doesntContain(fn (Task $candidate) => $candidate->id === $task->id)) {
            $taskCollection = $taskCollection->push($task)->values();
        }

        $summariesByDate = $this->buildDateInventorySummaries($owner, $taskCollection, true);
        $taskSummary = $summariesByDate[$dateKey]['tasks'][$task->id] ?? null;
        $context = $taskSummary['inventory_context'] ?? [
            'status' => 'not_required',
            'is_actionable' => true,
            'shortage_count' => 0,
            'requirements' => [],
            'missing_resources' => [],
        ];

        if (! ($taskSummary['can_complete'] ?? ($context['is_actionable'] ?? false))) {
            $this->throwInsufficientTaskInventory($context);
        }

        return $context;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function consumeTaskRequirements(GardenOwner $owner, Task $task): array
    {
        $task->loadMissing('requiredResources');
        $snapshots = $this->prepareTaskRequirementSnapshots($owner, $task, true);
        $context = $this->buildTaskInventoryContext($snapshots);
        $summary = [];

        if (($context['status'] ?? 'available') === 'shortage') {
            $this->throwInsufficientTaskInventory($context);
        }

        foreach ($snapshots as $snapshot) {
            /** @var TaskResourceRequirement $requirement */
            $requirement = $snapshot['requirement'];
            $requirementData = $snapshot['data'];

            if (! $requirementData['is_consumed']) {
                $summary[] = [
                    'name' => $requirementData['resource_name'],
                    'quantity' => $requirementData['required_quantity'],
                    'unit' => $requirementData['unit']->value,
                    'type' => $requirementData['inventory_item_type']->value,
                    'consumed' => false,
                ];

                continue;
            }

            $remainingToConsume = $requirementData['required_quantity'];

            /** @var InventoryItem $item */
            foreach ($snapshot['items'] as $item) {
                if ($remainingToConsume <= 0) {
                    break;
                }

                $availableOnItem = round((float) $item->quantity, 2);
                $deductedQuantity = round(min($availableOnItem, $remainingToConsume), 2);

                if ($deductedQuantity <= 0) {
                    continue;
                }

                $quantityAfter = round($availableOnItem - $deductedQuantity, 2);

                if ($quantityAfter < 0) {
                    throw ValidationException::withMessages([
                        'task' => ['Inventoriaus kiekis negali tapti neigiamas.'],
                    ]);
                }

                $item->update([
                    'quantity' => $quantityAfter,
                ]);

                InventoryUsageLog::query()->create([
                    'inventory_item_id' => $item->id,
                    'task_id' => $task->id,
                    'task_resource_requirement_id' => $requirement->id,
                    'garden_owner_id' => $owner->id,
                    'change_type' => 'consumed',
                    'quantity_before' => $availableOnItem,
                    'quantity_delta' => -1 * $deductedQuantity,
                    'quantity_after' => $quantityAfter,
                    'unit' => $requirementData['unit']->value,
                    'metadata' => [
                        'resource_name' => $requirementData['resource_name'],
                        'task_name' => $task->name,
                    ],
                    'created_at' => now(),
                ]);

                $remainingToConsume = round($remainingToConsume - $deductedQuantity, 2);
            }

            $summary[] = [
                'name' => $requirementData['resource_name'],
                'quantity' => $requirementData['required_quantity'],
                'unit' => $requirementData['unit']->value,
                'type' => $requirementData['inventory_item_type']->value,
                'consumed' => true,
            ];
        }

        return $summary;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildPlanningLedger(?GardenOwner $owner): array
    {
        if (! $owner) {
            return [];
        }

        return $this->listForOwner($owner)
            ->groupBy(fn (InventoryItem $item) => $this->ledgerKey(
                $this->normalizeInventoryName($item->name),
                $item->inventory_item_type instanceof InventoryItemType ? $item->inventory_item_type : InventoryItemType::from($item->type),
                $item->unit instanceof InventoryUnit ? $item->unit : InventoryUnit::from((string) $item->unit),
            ))
            ->map(function (Collection $items): array {
                $first = $items->first();
                $availableQuantity = round((float) $items->sum('quantity'), 2);
                $type = $first?->inventory_item_type instanceof InventoryItemType
                    ? $first->inventory_item_type
                    : InventoryItemType::from((string) ($first?->type ?? InventoryItemType::Material->value));
                $unit = $first?->unit instanceof InventoryUnit
                    ? $first->unit
                    : InventoryUnit::from((string) ($first?->unit ?? InventoryUnit::Unit->value));

                return [
                    'normalized_name' => $first?->normalized_name ?? $this->normalizeInventoryName((string) $first?->name),
                    'display_name' => $first?->name ?? '',
                    'available_quantity' => $availableQuantity,
                    'remaining_quantity' => $availableQuantity,
                    'planned_used_quantity' => 0.0,
                    'type' => $type->value,
                    'unit' => $unit->value,
                    'minimum_quantity' => round((float) ($first?->minimum_quantity ?? 0), 2),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $ledger
     * @return array<string, mixed>
     */
    public function reserveRequirementForPlan(array &$ledger, array $requirement): array
    {
        $normalizedRequirement = $this->normalizeRequirement($requirement);
        $normalizedName = $normalizedRequirement['normalized_name'];
        $expectedTypeValue = $normalizedRequirement['inventory_item_type']->value;
        $unitValue = $normalizedRequirement['unit']->value;
        $isConsumed = $normalizedRequirement['is_consumed'];
        $quantity = $normalizedRequirement['required_quantity'];

        if ($normalizedName === '' || $quantity <= 0) {
            return [
                'status' => 'invalid',
                'resource_name' => $normalizedRequirement['resource_name'],
                'expected_item_type' => $expectedTypeValue,
                'unit' => $unitValue,
                'required_quantity' => $quantity,
                'available_before' => 0.0,
                'reserved_quantity' => 0.0,
                'shortage_quantity' => max(0, $quantity),
                'remaining_after' => 0.0,
                'is_consumed' => $isConsumed,
            ];
        }

        $ledgerKey = $this->ledgerKey(
            $normalizedName,
            $normalizedRequirement['inventory_item_type'],
            $normalizedRequirement['unit'],
        );

        if (! isset($ledger[$ledgerKey])) {
            $ledger[$ledgerKey] = [
                'normalized_name' => $normalizedName,
                'display_name' => $normalizedRequirement['resource_name'],
                'available_quantity' => 0.0,
                'remaining_quantity' => 0.0,
                'planned_used_quantity' => 0.0,
                'type' => $expectedTypeValue,
                'unit' => $unitValue,
                'minimum_quantity' => 0.0,
            ];
        }

        $availableBefore = round((float) $ledger[$ledgerKey]['remaining_quantity'], 2);
        $reservedQuantity = $isConsumed
            ? round(min($availableBefore, $quantity), 2)
            : round(min($availableBefore, $quantity), 2);
        $shortageQuantity = round(max(0, $quantity - $reservedQuantity), 2);

        if ($isConsumed) {
            $ledger[$ledgerKey]['remaining_quantity'] = round($availableBefore - $reservedQuantity, 2);
            $ledger[$ledgerKey]['planned_used_quantity'] = round(
                (float) $ledger[$ledgerKey]['planned_used_quantity'] + $reservedQuantity,
                2
            );
        }

        return [
            'status' => $shortageQuantity > 0 ? 'shortage' : ($isConsumed ? 'reserved' : 'available'),
            'resource_name' => $ledger[$ledgerKey]['display_name'],
            'expected_item_type' => $expectedTypeValue,
            'unit' => $unitValue,
            'available_before' => $availableBefore,
            'required_quantity' => round($quantity, 2),
            'reserved_quantity' => $reservedQuantity,
            'shortage_quantity' => $shortageQuantity,
            'remaining_after' => round((float) $ledger[$ledgerKey]['remaining_quantity'], 2),
            'is_consumed' => $isConsumed,
            'minimum_quantity' => round((float) $ledger[$ledgerKey]['minimum_quantity'], 2),
        ];
    }

    /**
     * @param  iterable<int, Task>  $tasks
     * @return array<string, array<string, mixed>>
     */
    private function buildDateInventorySummaries(?GardenOwner $owner, iterable $tasks, bool $lock): array
    {
        $taskCollection = collect($tasks)
            ->filter(fn ($task) => $task instanceof Task)
            ->values();

        if ($taskCollection->isEmpty()) {
            return [];
        }

        $taskCollection->each(function (Task $task): void {
            $task->loadMissing('requiredResources');
        });

        return $taskCollection
            ->groupBy(fn (Task $task) => $this->taskDateKey($task) ?? 'undated')
            ->map(function (\Illuminate\Support\Collection $dateTasks, string $dateKey) use ($owner, $lock): array {
                $primaryPendingTasks = $dateTasks
                    ->filter(fn (Task $task) => $this->isPrimaryPendingTask($task))
                    ->values();
                $buyTasks = $dateTasks
                    ->filter(fn (Task $task) => $this->isBuyTask($task))
                    ->values();
                $resourceSummaries = $this->buildDayRequirementSummaries($owner, $primaryPendingTasks, $buyTasks, $lock);
                $resourceSummariesByKey = collect($resourceSummaries)->keyBy('resource_key');
                $taskContexts = $dateTasks
                    ->mapWithKeys(function (Task $task) use ($resourceSummariesByKey): array {
                        $taskContext = $this->buildTaskInventoryContextFromDaySummary($task, $resourceSummariesByKey);

                        return [
                            $task->id => [
                                'inventory_context' => $taskContext,
                                'can_complete' => $this->isBuyTask($task)
                                    ? (bool) ($taskContext['is_actionable'] ?? true)
                                    : (bool) ($taskContext['is_actionable'] ?? false),
                            ],
                        ];
                    })
                    ->all();
                $shortageResources = collect($resourceSummaries)
                    ->filter(fn (array $summary) => (float) ($summary['shortage_quantity'] ?? 0) > 0)
                    ->values();
                $buyTaskSummaries = $buyTasks->map(function (Task $task): array {
                    return [
                        'id' => $task->id,
                        'name' => $task->name,
                        'status' => $task->state?->value ?? $task->status,
                        'item' => $task->item,
                        'item_quantity' => $task->item_quantity === null ? null : (float) $task->item_quantity,
                    ];
                })->values()->all();

                return [
                    'date' => $dateKey === 'undated' ? null : $dateKey,
                    'status' => $resourceSummaries === []
                        ? 'not_required'
                        : ($shortageResources->isEmpty() ? 'available' : 'shortage'),
                    'is_actionable' => $resourceSummaries !== [] && $shortageResources->isEmpty(),
                    'resource_count' => count($resourceSummaries),
                    'shortage_count' => $shortageResources->count(),
                    'resources' => array_values($resourceSummaries),
                    'buy_task_ids' => collect($buyTaskSummaries)->pluck('id')->values()->all(),
                    'buy_tasks' => $buyTaskSummaries,
                    'tasks' => $taskContexts,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, Task>  $primaryPendingTasks
     * @param  Collection<int, Task>  $buyTasks
     * @return array<int, array<string, mixed>>
     */
    private function buildDayRequirementSummaries(
        ?GardenOwner $owner,
        Collection $primaryPendingTasks,
        Collection $buyTasks,
        bool $lock,
    ): array {
        $aggregated = [];

        foreach ($primaryPendingTasks as $task) {
            foreach ($task->requiredResources as $requirement) {
                $data = $this->normalizeRequirement($requirement);
                $resourceKey = $this->resourcePlanningKey($data['normalized_name'], $data['inventory_item_type'], $data['unit']);

                if (! isset($aggregated[$resourceKey])) {
                    $aggregated[$resourceKey] = [
                        'resource_key' => $resourceKey,
                        'resource_name' => $data['resource_name'],
                        'normalized_name' => $data['normalized_name'],
                        'inventory_item_type' => $data['inventory_item_type']->value,
                        'unit' => $data['unit']->value,
                        'required_quantity' => 0.0,
                        'available_quantity' => 0.0,
                        'shortage_quantity' => 0.0,
                        'is_consumed' => $data['is_consumed'],
                        'consumption_mode' => $data['is_consumed'] ? 'consumable' : 'reusable',
                        'task_ids' => [],
                        'task_names' => [],
                        'buy_task_ids' => [],
                    ];
                }

                $aggregated[$resourceKey]['required_quantity'] = round(
                    (float) $aggregated[$resourceKey]['required_quantity'] + $data['required_quantity'],
                    2
                );
                $aggregated[$resourceKey]['task_ids'][] = $task->id;
                $aggregated[$resourceKey]['task_names'][] = $task->name;
            }
        }

        foreach ($aggregated as $resourceKey => $summary) {
            $itemsQuery = $owner
                ? $this->matchingItemsQuery(
                    $owner,
                    $summary['normalized_name'],
                    InventoryItemType::from($summary['inventory_item_type']),
                    InventoryUnit::from($summary['unit']),
                )
                : null;

            if ($lock && $itemsQuery) {
                $itemsQuery = $itemsQuery->lockForUpdate();
            }

            $items = $itemsQuery ? $itemsQuery->get() : new EloquentCollection();
            $availableQuantity = round((float) $items->sum('quantity'), 2);

            $aggregated[$resourceKey]['available_quantity'] = $availableQuantity;
            $aggregated[$resourceKey]['shortage_quantity'] = round(
                max(0, (float) $summary['required_quantity'] - $availableQuantity),
                2
            );
            $aggregated[$resourceKey]['task_ids'] = array_values(array_unique($aggregated[$resourceKey]['task_ids']));
            $aggregated[$resourceKey]['task_names'] = array_values(array_unique($aggregated[$resourceKey]['task_names']));
        }

        foreach ($buyTasks as $buyTask) {
            foreach ($buyTask->requiredResources as $requirement) {
                $data = $this->normalizeRequirement($requirement);
                $resourceKey = $this->resourcePlanningKey($data['normalized_name'], $data['inventory_item_type'], $data['unit']);

                if (! isset($aggregated[$resourceKey])) {
                    continue;
                }

                $aggregated[$resourceKey]['buy_task_ids'][] = $buyTask->id;
            }
        }

        return collect($aggregated)
            ->map(function (array $summary): array {
                $summary['buy_task_ids'] = array_values(array_unique($summary['buy_task_ids']));
                $summary['is_sufficient'] = (float) $summary['shortage_quantity'] <= 0;

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
    private function buildTaskInventoryContextFromDaySummary(Task $task, \Illuminate\Support\Collection $resourceSummariesByKey): array
    {
        if ($this->isBuyTask($task)) {
            $buyResource = $task->requiredResources->first();
            $resourceSummary = $buyResource
                ? $resourceSummariesByKey->get($this->resourcePlanningKey(
                    $buyResource->normalized_name,
                    $buyResource->inventory_item_type instanceof InventoryItemType ? $buyResource->inventory_item_type : InventoryItemType::from((string) $buyResource->inventory_item_type),
                    $buyResource->unit instanceof InventoryUnit ? $buyResource->unit : InventoryUnit::from((string) $buyResource->unit),
                ))
                : null;

            return [
                'status' => 'purchase_required',
                'is_actionable' => true,
                'shortage_count' => $resourceSummary && (float) ($resourceSummary['shortage_quantity'] ?? 0) > 0 ? 1 : 0,
                'requirements' => [],
                'missing_resources' => [],
                'buy_task_ids' => [$task->id],
                'blocked_task_ids' => $resourceSummary['task_ids'] ?? [],
                'calendar_date' => $this->taskDateKey($task),
            ];
        }

        if (! $this->isPendingState($task) || $task->requiredResources->isEmpty()) {
            return [
                'status' => $task->requiredResources->isEmpty() ? 'not_required' : 'available',
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
                $data = $this->normalizeRequirement($requirement);
                $resourceSummary = $resourceSummariesByKey->get(
                    $this->resourcePlanningKey($data['normalized_name'], $data['inventory_item_type'], $data['unit'])
                );
                $dailyShortage = round((float) ($resourceSummary['shortage_quantity'] ?? 0), 2);
                $localShortage = $dailyShortage > 0
                    ? round(min($data['required_quantity'], $dailyShortage), 2)
                    : 0.0;

                return [
                    'requirement_id' => $requirement->id,
                    'resource_name' => $data['resource_name'],
                    'normalized_name' => $data['normalized_name'],
                    'inventory_item_type' => $data['inventory_item_type']->value,
                    'unit' => $data['unit']->value,
                    'required_quantity' => $data['required_quantity'],
                    'available_quantity' => round((float) ($resourceSummary['available_quantity'] ?? 0), 2),
                    'shortage_quantity' => $localShortage,
                    'daily_required_quantity' => round((float) ($resourceSummary['required_quantity'] ?? $data['required_quantity']), 2),
                    'daily_available_quantity' => round((float) ($resourceSummary['available_quantity'] ?? 0), 2),
                    'daily_shortage_quantity' => $dailyShortage,
                    'is_consumed' => $data['is_consumed'],
                    'is_sufficient' => $dailyShortage <= 0,
                    'buy_task_ids' => $resourceSummary['buy_task_ids'] ?? [],
                    'blocked_task_ids' => $resourceSummary['task_ids'] ?? [],
                ];
            })
            ->values();
        $missingResources = $requirements
            ->filter(fn (array $requirement) => (float) ($requirement['daily_shortage_quantity'] ?? 0) > 0)
            ->values();

        return [
            'status' => $missingResources->isEmpty() ? 'available' : 'shortage',
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

    private function isPrimaryPendingTask(Task $task): bool
    {
        return ! $this->isBuyTask($task) && $this->isPendingState($task);
    }

    private function isBuyTask(Task $task): bool
    {
        return ($task->task_type ?? $task->type) === 'buy';
    }

    private function isPendingState(Task $task): bool
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

    private function resourcePlanningKey(string $normalizedName, InventoryItemType $type, InventoryUnit $unit): string
    {
        return $this->ledgerKey($normalizedName, $type, $unit);
    }

    private function queryForOwner(GardenOwner $owner): Builder
    {
        return InventoryItem::query()->where('garden_owner_id', $owner->id);
    }

    private function matchingItemsQuery(
        GardenOwner $owner,
        string $normalizedName,
        InventoryItemType $type,
        InventoryUnit $unit,
    ): Builder {
        return $this->queryForOwner($owner)
            ->where('inventory_item_type', $type->value)
            ->where('unit', $unit->value)
            ->where('normalized_name', $normalizedName)
            ->orderBy('id');
    }

    /**
     * @return array<int, array{requirement: TaskResourceRequirement, data: array<string, mixed>, items: EloquentCollection<int, InventoryItem>, available_quantity: float, shortage_quantity: float}>
     */
    private function prepareTaskRequirementSnapshots(?GardenOwner $owner, Task $task, bool $lock): array
    {
        $task->loadMissing('requiredResources');

        return $task->requiredResources
            ->map(function (TaskResourceRequirement $requirement) use ($owner, $lock): array {
                $requirementData = $this->normalizeRequirement($requirement);
                $matchingItems = $owner
                    ? $this->matchingItemsQuery(
                        $owner,
                        $requirementData['normalized_name'],
                        $requirementData['inventory_item_type'],
                        $requirementData['unit'],
                    )
                    : null;

                if ($lock && $matchingItems) {
                    $matchingItems = $matchingItems->lockForUpdate();
                }

                $items = $matchingItems ? $matchingItems->get() : new EloquentCollection();
                $availableQuantity = round((float) $items->sum('quantity'), 2);
                $shortageQuantity = round(max(0, $requirementData['required_quantity'] - $availableQuantity), 2);

                return [
                    'requirement' => $requirement,
                    'data' => $requirementData,
                    'items' => $items,
                    'available_quantity' => $availableQuantity,
                    'shortage_quantity' => $shortageQuantity,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{requirement: TaskResourceRequirement, data: array<string, mixed>, items: EloquentCollection<int, InventoryItem>, available_quantity: float, shortage_quantity: float}>  $snapshots
     * @return array<string, mixed>
     */
    private function buildTaskInventoryContext(array $snapshots): array
    {
        if ($snapshots === []) {
            return [
                'status' => 'not_required',
                'is_actionable' => true,
                'shortage_count' => 0,
                'requirements' => [],
                'missing_resources' => [],
            ];
        }

        $requirements = collect($snapshots)
            ->map(function (array $snapshot): array {
                $data = $snapshot['data'];

                return [
                    'requirement_id' => $snapshot['requirement']->id,
                    'resource_name' => $data['resource_name'],
                    'normalized_name' => $data['normalized_name'],
                    'inventory_item_type' => $data['inventory_item_type']->value,
                    'unit' => $data['unit']->value,
                    'required_quantity' => $data['required_quantity'],
                    'available_quantity' => $snapshot['available_quantity'],
                    'shortage_quantity' => $snapshot['shortage_quantity'],
                    'is_consumed' => $data['is_consumed'],
                    'is_sufficient' => $snapshot['shortage_quantity'] <= 0,
                ];
            })
            ->values();

        $missingResources = $requirements
            ->filter(fn (array $requirement) => $requirement['shortage_quantity'] > 0)
            ->values();

        return [
            'status' => $missingResources->isEmpty() ? 'available' : 'shortage',
            'is_actionable' => $missingResources->isEmpty(),
            'shortage_count' => $missingResources->count(),
            'requirements' => $requirements->all(),
            'missing_resources' => $missingResources->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function throwInsufficientTaskInventory(array $context): never
    {
        $missingMessages = collect($context['missing_resources'] ?? [])
            ->map(function (array $requirement): string {
                return sprintf(
                    '%s: truksta %s %s.',
                    $requirement['resource_name'],
                    number_format((float) $requirement['shortage_quantity'], 2, '.', ''),
                    $requirement['unit'],
                );
            })
            ->values()
            ->all();

        throw ValidationException::withMessages([
            'task' => ['Uzduoties uzbaigti negalima, kol inventoriaus nepakanka visiems privalomiems resursams.'],
            'missing_resources' => $missingMessages,
        ]);
    }

    /**
     * @param  array<string, mixed>|TaskResourceRequirement  $requirement
     * @return array{resource_name:string,normalized_name:string,inventory_item_type:InventoryItemType,unit:InventoryUnit,required_quantity:float,shortage_quantity:float,is_consumed:bool}
     */
    private function normalizeRequirement(array|TaskResourceRequirement $requirement): array
    {
        $resourceName = (string) ($requirement instanceof TaskResourceRequirement
            ? $requirement->resource_name
            : ($requirement['resource_name'] ?? $requirement['name'] ?? ''));
        $normalizedName = $this->normalizeInventoryName(
            (string) ($requirement instanceof TaskResourceRequirement
                ? $requirement->normalized_name
                : ($requirement['normalized_name'] ?? $resourceName))
        );
        $typeValue = $requirement instanceof TaskResourceRequirement
            ? ($requirement->inventory_item_type?->value ?? $requirement->inventory_item_type)
            : ($requirement['inventory_item_type'] ?? $requirement['type'] ?? InventoryItemType::Material->value);
        $unitValue = $requirement instanceof TaskResourceRequirement
            ? ($requirement->unit?->value ?? $requirement->unit)
            : ($requirement['unit'] ?? InventoryUnit::Unit->value);

        return [
            'resource_name' => $resourceName,
            'normalized_name' => $normalizedName,
            'inventory_item_type' => $typeValue instanceof InventoryItemType ? $typeValue : InventoryItemType::from((string) $typeValue),
            'unit' => $unitValue instanceof InventoryUnit ? $unitValue : InventoryUnit::from((string) $unitValue),
            'required_quantity' => round((float) ($requirement instanceof TaskResourceRequirement
                ? $requirement->required_quantity
                : ($requirement['required_quantity'] ?? $requirement['quantity'] ?? 0)), 2),
            'shortage_quantity' => round((float) ($requirement instanceof TaskResourceRequirement
                ? $requirement->shortage_quantity
                : ($requirement['shortage_quantity'] ?? 0)), 2),
            'is_consumed' => (bool) ($requirement instanceof TaskResourceRequirement
                ? $requirement->is_consumed
                : ($requirement['is_consumed'] ?? true)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function prepareInventoryPayload(array $data, ?InventoryItem $currentItem = null): array
    {
        $payload = $data;
        $type = $payload['inventory_item_type'] ?? $payload['type'] ?? $currentItem?->inventory_item_type?->value ?? $currentItem?->type;
        $unit = $payload['unit'] ?? $currentItem?->unit?->value ?? $currentItem?->unit ?? InventoryUnit::Unit->value;

        $payload['inventory_item_type'] = $type;
        $payload['type'] = $type;
        $payload['unit'] = $unit;
        $payload['minimum_quantity'] = array_key_exists('minimum_quantity', $payload)
            ? round((float) $payload['minimum_quantity'], 2)
            : round((float) ($currentItem?->minimum_quantity ?? 0), 2);

        if (array_key_exists('name', $payload)) {
            $payload['normalized_name'] = $this->normalizeInventoryName((string) $payload['name']);
        }

        return $payload;
    }

    private function ledgerKey(string $normalizedName, InventoryItemType $type, InventoryUnit $unit): string
    {
        return implode('|', [$type->value, $unit->value, $normalizedName]);
    }

    private function normalizeInventoryName(string $name): string
    {
        return Str::of($name)->trim()->lower()->value();
    }
}
