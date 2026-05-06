<?php

namespace App\Services\Calendar;

use App\Enums\TaskState;
use App\Enums\TaskType;
use App\Models\HarvestRecord;
use App\Models\PlantConditionHistory;
use App\Models\Task;
use App\Services\Inventory\InventoryService;
use App\Services\Plant\PlantCareService;
use App\Services\Plant\PlantLifecycleService;
use App\Services\Plot\HarvestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskWorkflowService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly HarvestService $harvestService,
        private readonly PlantCareService $plantCareService,
        private readonly PlantLifecycleService $plantLifecycleService,
    ) {
    }

    /**
     * @return array{task: Task, harvest_record: HarvestRecord|null, condition_history_entry: PlantConditionHistory|null}
     */
    public function complete(
        Task $task,
        ?array $materialsUsed = null,
        ?array $conditionReview = null,
        ?array $harvest = null,
    ): array {
        return DB::transaction(function () use ($task, $materialsUsed, $conditionReview, $harvest) {
            $task = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->with([
                    'requiredResources',
                    'taskCalendar.plot.gardenOwner',
                    'plant.catalogPlant.plantCare',
                    'plant.conditionHistory',
                    'plant.harvestRecords',
                ])
                ->firstOrFail();

            $this->ensureTaskPending($task, 'ivykdyti');

            $summary = [];
            $inventoryOwner = $task->taskCalendar?->plot?->gardenOwner;
            $isReplenishmentTask = ($task->task_type ?? $task->type) === TaskType::Buy->value;

            if ($inventoryOwner && $task->requiredResources->isNotEmpty()) {
                if (! $isReplenishmentTask) {
                    $dayTasks = Task::query()
                        ->where('task_calendar_id', $task->task_calendar_id)
                        ->whereDate('date', $task->date->toDateString())
                        ->where('state', TaskState::Pending->value)
                        ->where('task_type', '!=', TaskType::Buy->value)
                        ->with('requiredResources')
                        ->lockForUpdate()
                        ->get();

                    $this->inventoryService->assertTaskCanBeCompletedForDay($inventoryOwner, $task, $dayTasks);
                }

                $summary = $isReplenishmentTask
                    ? $this->inventoryService->replenishFromTask($inventoryOwner, $task)
                    : $this->inventoryService->consumeTaskRequirements($inventoryOwner, $task);
            } elseif ($inventoryOwner) {
                foreach ($materialsUsed ?? [] as $material) {
                    $this->inventoryService->deductMaterialForOwner(
                        $inventoryOwner,
                        $material['name'],
                        (float) $material['quantity'],
                    );

                    $summary[] = [
                        'name' => $material['name'],
                        'quantity' => (float) $material['quantity'],
                        'unit' => null,
                        'type' => 'material',
                        'consumed' => true,
                    ];
                }
            }

            $harvestRecord = null;
            $conditionHistoryEntry = null;
            $workflowComments = [];

            if (($task->workflow_context['kind'] ?? null) === 'lifecycle_review') {
                if ($conditionReview === null) {
                    throw ValidationException::withMessages([
                        'condition_review' => ['Bukles perziuros uzduociai reikia perziuros sprendimo.'],
                    ]);
                }

                $reviewResult = $this->plantLifecycleService->completeReviewTask($task, $conditionReview);
                $conditionHistoryEntry = $reviewResult['history_entry'];
                $workflowComments[] = sprintf(
                    'Condition review applied: %s.',
                    $reviewResult['applied_condition']
                );
            }

            if (($task->task_type ?? $task->type) === TaskType::Harvest->value) {
                if ($harvest === null) {
                    throw ValidationException::withMessages([
                        'harvest' => ['Derliaus uzduoties uzbaigimui reikia derliaus duomenu.'],
                    ]);
                }

                if (! $inventoryOwner) {
                    throw ValidationException::withMessages([
                        'harvest' => ['Derliaus uzduotis neturi inventoriaus savininko.'],
                    ]);
                }

                $harvestRecord = $this->harvestService->registerForTask($task, $inventoryOwner, $harvest);
                $workflowComments[] = sprintf(
                    'Harvest recorded: %s on %s.',
                    number_format((float) $harvestRecord->quantity, 2, '.', ''),
                    $harvestRecord->harvested_on?->toDateString()
                );

                if ($task->plant) {
                    $care = $this->plantCareService->resolveEffectivePlantCare($task->plant);
                    $conditionHistoryEntry = $this->plantLifecycleService->recordPostHarvestCondition(
                        $task->plant,
                        $care,
                        $harvest['harvested_on'],
                        $harvest['notes'] ?? 'Harvest workflow completed.',
                    );
                    $workflowComments[] = sprintf(
                        'Post-harvest condition confirmed: %s.',
                        $conditionHistoryEntry->condition_type?->value ?? $conditionHistoryEntry->condition
                    );
                }
            }

            $task->update([
                'state' => TaskState::Completed,
                'status' => 'completed',
                'comment' => $this->appendComment(
                    $task->comment,
                    implode(PHP_EOL, array_filter([
                        $this->buildCompletionComment($summary),
                        $workflowComments === [] ? null : implode(PHP_EOL, $workflowComments),
                    ]))
                ),
            ]);

            return [
                'task' => $task->fresh(['plant.plantZone', 'taskCalendar', 'requiredResources']),
                'harvest_record' => $harvestRecord?->fresh(['plant.plantZone', 'task']),
                'condition_history_entry' => $conditionHistoryEntry?->fresh(),
            ];
        });
    }

    public function reject(Task $task, ?string $reason = null): Task
    {
        return DB::transaction(function () use ($task, $reason) {
            $this->ensureTaskPending($task, 'atsaukti');

            $task->update([
                'state' => TaskState::Canceled,
                'status' => 'canceled',
                'comment' => $this->appendComment(
                    $task->comment,
                    $reason ? "Atsaukimo priezastis: {$reason}" : null
                ),
            ]);

            return $task->fresh(['plant.plantZone', 'taskCalendar', 'requiredResources']);
        });
    }

    private function ensureTaskPending(Task $task, string $action): void
    {
        $state = $task->state?->value ?? $task->status;

        if ($state === TaskState::Pending->value) {
            return;
        }

        throw ValidationException::withMessages([
            'task' => ["Tik laukiancius veiksmus galima {$action}."],
        ]);
    }

    private function appendComment(?string $currentComment, ?string $addition): ?string
    {
        $parts = array_filter([$currentComment, $addition], fn (?string $value) => filled($value));

        return $parts === [] ? null : implode(PHP_EOL, $parts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $summary
     */
    private function buildCompletionComment(array $summary): ?string
    {
        if ($summary === []) {
            return null;
        }

        $parts = collect($summary)
            ->map(function (array $entry): string {
                $quantity = number_format((float) ($entry['quantity'] ?? 0), 2, '.', '');
                $unit = $entry['unit'] ? ' '.$entry['unit'] : '';
                $prefix = $entry['action_label']
                    ?? (($entry['consumed'] ?? false) ? 'Panaudota' : 'Patikrintas resursas');

                return sprintf('%s: %s (%s%s)', $prefix, $entry['name'], $quantity, $unit);
            })
            ->all();

        return implode(PHP_EOL, $parts);
    }
}
