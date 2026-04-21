<?php

namespace App\Services;

use App\Enums\TaskState;
use App\Enums\TaskType;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskWorkflowService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {
    }

    public function complete(Task $task, ?array $materialsUsed = null): Task
    {
        return DB::transaction(function () use ($task, $materialsUsed) {
            $task = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->with(['requiredResources', 'taskCalendar.plot.gardenOwner'])
                ->firstOrFail();

            $this->ensureTaskPending($task, 'ivykdyti');

            $summary = [];
            $inventoryOwner = $task->taskCalendar?->plot?->gardenOwner;

            if ($inventoryOwner && $task->requiredResources->isNotEmpty()) {
                if (($task->task_type ?? $task->type) !== TaskType::Buy->value) {
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

                $summary = $this->inventoryService->consumeTaskRequirements($inventoryOwner, $task);
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

            $task->update([
                'state' => TaskState::Completed,
                'status' => 'completed',
                'comment' => $this->appendComment(
                    $task->comment,
                    $this->buildCompletionComment($summary)
                ),
            ]);

            return $task->fresh(['plant.plantZone', 'taskCalendar', 'requiredResources']);
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
                    $prefix = ($entry['consumed'] ?? false) ? 'Panaudota' : 'Patikrintas resursas';

                    return sprintf('%s: %s (%s%s)', $prefix, $entry['name'], $quantity, $unit);
                })
            ->all();

        return implode(PHP_EOL, $parts);
    }
}
