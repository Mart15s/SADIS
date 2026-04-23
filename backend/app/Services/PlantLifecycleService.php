<?php

namespace App\Services;

use App\Enums\ConditionType;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Models\HarvestRecord;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantConditionHistory;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PlantLifecycleService
{
    public function __construct(
        private readonly PlantConditionHistoryService $plantConditionHistoryService,
        private readonly PlantLifecyclePhaseService $plantLifecyclePhaseService,
    ) {
    }

    /**
     * @param  Collection<int, PlantConditionHistory>|null  $conditionHistory
     * @param  Collection<int, HarvestRecord>|null  $harvestRecords
     * @return array<string, mixed>
     */
    public function buildSummary(
        Plant $plant,
        PlantCare $care,
        ?Collection $conditionHistory = null,
        ?Collection $harvestRecords = null,
    ): array {
        $conditionHistory ??= $plant->relationLoaded('conditionHistory')
            ? $plant->conditionHistory
            : $plant->conditionHistory()->latest('measured_at')->latest('id')->get();
        $harvestRecords ??= $plant->relationLoaded('harvestRecords')
            ? $plant->harvestRecords
            : $plant->harvestRecords()->latest('harvested_on')->latest('id')->get();

        $timeline = $this->stageTimeline($plant, $care);
        $currentCondition = $plant->condition?->value ?? (string) $plant->condition;
        $anchorDate = $this->resolveAnchorDate($plant, $care, $currentCondition, $conditionHistory, $harvestRecords, $timeline);
        $nextReview = $this->resolveNextReview($plant, $care, $currentCondition, $anchorDate);
        $nextHarvest = $this->resolveNextHarvest($plant, $care, $currentCondition, $anchorDate, $harvestRecords);
        $latestHistory = $conditionHistory->sortByDesc(fn (PlantConditionHistory $entry) => sprintf(
            '%020d-%020d',
            $entry->measured_at?->timestamp ?? 0,
            $entry->id
        ))->first();
        $latestHarvest = $harvestRecords->sortByDesc(fn (HarvestRecord $record) => sprintf(
            '%020d-%020d',
            $record->harvested_on?->timestamp ?? 0,
            $record->id
        ))->first();

        return [
            'current_condition' => $currentCondition,
            'current_condition_anchor_date' => $anchorDate?->toDateString(),
            'next_review' => $nextReview ? [
                'current_condition' => $currentCondition,
                'target_condition' => $nextReview['target_condition'],
                'expected_on' => $nextReview['expected_on']->toDateString(),
                'is_overdue' => $nextReview['is_overdue'],
                'duration_days' => $nextReview['duration_days'],
            ] : null,
            'next_harvest' => $nextHarvest ? [
                'expected_on' => $nextHarvest['expected_on']->toDateString(),
                'is_overdue' => $nextHarvest['is_overdue'],
                'post_harvest_condition' => $nextHarvest['post_harvest_condition'],
            ] : null,
            'scheduled_stage_starts' => collect($timeline['stage_starts'])
                ->map(fn (Carbon $date) => $date->toDateString())
                ->all(),
            'latest_condition_entry' => $latestHistory ? [
                'id' => $latestHistory->id,
                'measured_at' => $latestHistory->measured_at?->toIso8601String(),
                'condition' => $this->conditionValue($latestHistory),
                'notes' => $latestHistory->notes,
            ] : null,
            'latest_harvest_record' => $latestHarvest ? [
                'id' => $latestHarvest->id,
                'harvested_on' => $latestHarvest->harvested_on?->toDateString(),
                'quantity' => $latestHarvest->quantity === null ? null : (float) $latestHarvest->quantity,
                'notes' => $latestHarvest->notes,
            ] : null,
            'supports_regeneration' => $this->isReusable($plant, $care),
        ];
    }

    /**
     * @param  Collection<int, PlantConditionHistory>|null  $conditionHistory
     * @param  Collection<int, HarvestRecord>|null  $harvestRecords
     * @return array<int, array<string, mixed>>
     */
    public function buildActionsForDate(
        Plant $plant,
        PlantCare $care,
        array $phaseResult,
        Carbon $generationDate,
        ?Collection $conditionHistory = null,
        ?Collection $harvestRecords = null,
    ): array {
        $actions = [];

        if (($phaseResult['is_transition_day'] ?? false) && isset($phaseResult['transition']['from'], $phaseResult['transition']['to'])) {
            $fromPhase = (string) $phaseResult['transition']['from'];
            $toPhase = (string) $phaseResult['transition']['to'];

            $actions[] = [
                'type' => TaskType::Rest->value,
                'name' => sprintf('Review %s state: %s -> %s', $plant->name, $fromPhase, $toPhase),
                'priority' => TaskPriority::Medium->value,
                'reason' => 'The simulated lifecycle crosses into a new expected phase on this day.',
                'comment' => 'Review the real plant condition before confirming the expected lifecycle transition.',
                'workflow_context' => [
                    'kind' => 'lifecycle_review',
                    'review' => [
                        'current_condition' => $plant->condition?->value ?? (string) $plant->condition,
                        'from_phase' => $fromPhase,
                        'target_condition' => $toPhase,
                        'expected_on' => $generationDate->toDateString(),
                        'is_overdue' => false,
                        'elapsed_days_from_planted' => $phaseResult['elapsed_days_from_planted'],
                    ],
                ],
            ];
        }

        if ($this->shouldCreateHarvestTask($plant, $care, $generationDate, $harvestRecords)) {
            $actions[] = [
                'type' => TaskType::Harvest->value,
                'name' => "Harvest {$plant->name}",
                'priority' => TaskPriority::High->value,
                'reason' => 'The simulated lifecycle has reached the harvest checkpoint for this planting.',
                'comment' => 'Record the harvest quantity and confirm the post-harvest condition.',
                'required_resources' => [[
                    'resource_name' => 'Harvest box',
                    'normalized_name' => 'harvest box',
                    'inventory_item_type' => 'tool',
                    'unit' => 'unit',
                    'required_quantity' => 1,
                    'shortage_quantity' => 0,
                    'resource_mode' => 'reusable',
                    'is_consumed' => false,
                ]],
                'workflow_context' => [
                    'kind' => 'harvest',
                    'harvest' => [
                        'expected_on' => $generationDate->toDateString(),
                        'is_overdue' => false,
                        'post_harvest_condition' => $this->resolvePostHarvestCondition($plant, $care),
                    ],
                ],
            ];
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{history_entry: PlantConditionHistory, plant: Plant, applied_condition: string}
     */
    public function completeReviewTask(Task $task, array $payload): array
    {
        $task->loadMissing('plant');
        $plant = $task->plant;

        if (! $plant) {
            throw ValidationException::withMessages([
                'task' => ['Bukles perziuros uzduotis neturi susieto augalo.'],
            ]);
        }

        $workflowContext = $task->workflow_context['review'] ?? [];
        $currentCondition = $plant->condition?->value ?? (string) $plant->condition;
        $action = (string) ($payload['action'] ?? '');
        $appliedCondition = match ($action) {
            'confirm' => (string) ($workflowContext['target_condition'] ?? ''),
            'keep_current' => $currentCondition,
            'adjust' => (string) ($payload['condition'] ?? ''),
            default => '',
        };

        if ($appliedCondition === '') {
            throw ValidationException::withMessages([
                'condition_review' => ['Nepakanka duomenu bukles perziurai patvirtinti.'],
            ]);
        }

        $measuredAt = $payload['measured_at'] ?? $task->date?->toDateString() ?? now()->toDateString();
        $notes = $payload['notes'] ?? null;

        if ($action === 'confirm' && filled($workflowContext['target_condition'])) {
            $notes = trim(implode(' ', array_filter([
                $notes,
                sprintf('Confirmed lifecycle review towards "%s".', $workflowContext['target_condition']),
            ]))) ?: null;
        }

        if ($action === 'keep_current') {
            $notes = trim(implode(' ', array_filter([
                $notes,
                'Suggested transition was reviewed and kept at the current stage.',
            ]))) ?: null;
        }

        $historyEntry = $this->plantConditionHistoryService->record(
            $plant,
            $appliedCondition,
            Carbon::parse($measuredAt)->setTime(12, 0)->toDateTimeString(),
            $notes,
        );

        return [
            'history_entry' => $historyEntry,
            'plant' => $plant->fresh(['plot', 'plantZone', 'catalogPlant.plantCare']),
            'applied_condition' => $appliedCondition,
        ];
    }

    public function resolvePostHarvestCondition(Plant $plant, PlantCare $care): string
    {
        return $this->isReusable($plant, $care)
            ? ConditionType::Regenerating->value
            : ConditionType::Dried->value;
    }

    public function recordPostHarvestCondition(
        Plant $plant,
        PlantCare $care,
        Carbon|string $measuredAt,
        ?string $notes = null,
    ): PlantConditionHistory {
        $nextCondition = $this->resolvePostHarvestCondition($plant, $care);

        return $this->plantConditionHistoryService->record(
            $plant,
            $nextCondition,
            $measuredAt instanceof Carbon ? $measuredAt->setTime(12, 0)->toDateTimeString() : $measuredAt,
            $notes,
            null,
            $nextCondition === ConditionType::Diseased->value,
        );
    }

    /**
     * @param  Collection<int, HarvestRecord>|null  $harvestRecords
     */
    private function shouldCreateHarvestTask(
        Plant $plant,
        PlantCare $care,
        Carbon $generationDate,
        ?Collection $harvestRecords = null,
    ): bool {
        $harvestRecords ??= $plant->relationLoaded('harvestRecords')
            ? $plant->harvestRecords
            : $plant->harvestRecords()->latest('harvested_on')->latest('id')->get();
        $harvestDueDate = $this->stageTimeline($plant, $care)['harvest_on']->copy()->startOfDay();

        if (! $generationDate->copy()->startOfDay()->isSameDay($harvestDueDate)) {
            return false;
        }

        return ! $harvestRecords->contains(function (HarvestRecord $record) use ($harvestDueDate): bool {
            return $record->harvested_on?->copy()->startOfDay()->isSameDay($harvestDueDate);
        });
    }

    private function shouldScheduleOn(Carbon $expectedOn, Carbon $generationDate, Carbon $rangeStart): bool
    {
        $expected = $expectedOn->copy()->startOfDay();
        $generation = $generationDate->copy()->startOfDay();
        $range = $rangeStart->copy()->startOfDay();

        if ($generation->isSameDay($expected)) {
            return true;
        }

        return $expected->lt($range) && $generation->isSameDay($range);
    }

    /**
     * @return array{stage_starts: array<string, Carbon>, harvest_on: Carbon}
     */
    private function stageTimeline(Plant $plant, PlantCare $care): array
    {
        $plantDate = $plant->plant_date->copy()->startOfDay();
        $timeline = $this->plantLifecyclePhaseService->buildTimeline($care);
        $stageStarts = collect($timeline)
            ->mapWithKeys(fn (array $phase): array => [
                $phase['phase'] => $plantDate->copy()->addDays($phase['start_day']),
            ])
            ->all();
        $harvestOn = $plantDate->copy()->addDays(1 + max(0, (int) ($care->germinating_duration_days ?? 0))
            + max(0, (int) ($care->growing_duration_days ?? 0))
            + max(0, (int) ($care->flowering_duration_days ?? 0))
            + max(0, (int) ($care->mature_duration_days ?? 0)));

        return [
            'stage_starts' => $stageStarts,
            'harvest_on' => $harvestOn,
        ];
    }

    /**
     * @param  Collection<int, PlantConditionHistory>  $conditionHistory
     * @param  Collection<int, HarvestRecord>  $harvestRecords
     */
    private function resolveAnchorDate(
        Plant $plant,
        PlantCare $care,
        string $currentCondition,
        Collection $conditionHistory,
        Collection $harvestRecords,
        array $timeline,
    ): Carbon {
        $historyEntry = $conditionHistory
            ->filter(fn (PlantConditionHistory $entry) => $this->conditionValue($entry) === $currentCondition)
            ->sortByDesc(fn (PlantConditionHistory $entry) => sprintf(
                '%020d-%020d',
                $entry->measured_at?->timestamp ?? 0,
                $entry->id
            ))
            ->first();

        if ($historyEntry?->measured_at) {
            return $historyEntry->measured_at->copy()->startOfDay();
        }

        if ($currentCondition === ConditionType::Regenerating->value) {
            $latestHarvest = $harvestRecords
                ->sortByDesc(fn (HarvestRecord $record) => sprintf(
                    '%020d-%020d',
                    $record->harvested_on?->timestamp ?? 0,
                    $record->id
                ))
                ->first();

            if ($latestHarvest?->harvested_on) {
                return $latestHarvest->harvested_on->copy()->startOfDay();
            }
        }

        return ($timeline['stage_starts'][$currentCondition] ?? $plant->plant_date)->copy()->startOfDay();
    }

    /**
     * @return array{target_condition:string,expected_on:Carbon,is_overdue:bool,duration_days:int}|null
     */
    private function resolveNextReview(
        Plant $plant,
        PlantCare $care,
        string $currentCondition,
        Carbon $anchorDate,
    ): ?array {
        if (in_array($currentCondition, [
            ConditionType::Diseased->value,
            ConditionType::Dried->value,
            ConditionType::Mature->value,
        ], true)) {
            return null;
        }

        $targetCondition = $this->nextStageAfter($currentCondition, $care);

        if ($targetCondition === null) {
            return null;
        }

        $expectedOn = $anchorDate->copy()->addDays($this->durationForCurrentStage($currentCondition, $care));

        return [
            'target_condition' => $targetCondition,
            'expected_on' => $expectedOn,
            'is_overdue' => $expectedOn->lt(now()->startOfDay()),
            'duration_days' => $this->durationForCurrentStage($currentCondition, $care),
        ];
    }

    /**
     * @param  Collection<int, HarvestRecord>  $harvestRecords
     * @return array{expected_on:Carbon,is_overdue:bool,post_harvest_condition:string}|null
     */
    private function resolveNextHarvest(
        Plant $plant,
        PlantCare $care,
        string $currentCondition,
        Carbon $anchorDate,
        Collection $harvestRecords,
    ): ?array {
        if (in_array($currentCondition, [
            ConditionType::Diseased->value,
            ConditionType::Dried->value,
        ], true)) {
            return null;
        }

        $daysUntilHarvest = $this->daysUntilHarvestFromCondition($currentCondition, $care);

        if ($daysUntilHarvest === null) {
            return null;
        }

        if ($currentCondition === ConditionType::Mature->value) {
            $hasHarvestAfterAnchor = $harvestRecords->contains(function (HarvestRecord $record) use ($anchorDate): bool {
                return $record->harvested_on?->copy()->startOfDay()->gte($anchorDate);
            });

            if ($hasHarvestAfterAnchor) {
                return null;
            }
        }

        $expectedOn = $anchorDate->copy()->addDays($daysUntilHarvest);

        return [
            'expected_on' => $expectedOn,
            'is_overdue' => $expectedOn->lt(now()->startOfDay()),
            'post_harvest_condition' => $this->resolvePostHarvestCondition($plant, $care),
        ];
    }

    private function daysUntilHarvestFromCondition(string $currentCondition, PlantCare $care): ?int
    {
        if ($currentCondition === ConditionType::Mature->value) {
            return max(1, (int) ($care->mature_duration_days ?? 0));
        }

        $nextStage = $this->nextStageAfter($currentCondition, $care);

        if ($nextStage === null) {
            return null;
        }

        $remaining = $this->daysUntilHarvestFromCondition($nextStage, $care);

        if ($remaining === null) {
            return null;
        }

        return $this->durationForCurrentStage($currentCondition, $care) + $remaining;
    }

    private function nextStageAfter(string $currentCondition, PlantCare $care): ?string
    {
        $candidates = match ($currentCondition) {
            ConditionType::Planted->value => [
                ConditionType::Germinating->value,
                ConditionType::Growing->value,
                ConditionType::Flowering->value,
                ConditionType::Mature->value,
            ],
            ConditionType::Germinating->value,
            ConditionType::Regenerating->value => [
                ConditionType::Growing->value,
                ConditionType::Flowering->value,
                ConditionType::Mature->value,
            ],
            ConditionType::Growing->value => [
                ConditionType::Flowering->value,
                ConditionType::Mature->value,
            ],
            ConditionType::Flowering->value => [
                ConditionType::Mature->value,
            ],
            default => [],
        };

        foreach ($candidates as $candidate) {
            if ($candidate === ConditionType::Mature->value) {
                return $candidate;
            }

            $duration = match ($candidate) {
                ConditionType::Germinating->value => (int) ($care->germinating_duration_days ?? 0),
                ConditionType::Growing->value => (int) ($care->growing_duration_days ?? 0),
                ConditionType::Flowering->value => (int) ($care->flowering_duration_days ?? 0),
                default => 0,
            };

            if ($duration > 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function durationForCurrentStage(string $currentCondition, PlantCare $care): int
    {
        return match ($currentCondition) {
            ConditionType::Planted->value => 1,
            ConditionType::Germinating->value => max(1, (int) ($care->germinating_duration_days ?? 0)),
            ConditionType::Growing->value => max(1, (int) ($care->growing_duration_days ?? 0)),
            ConditionType::Flowering->value => max(1, (int) ($care->flowering_duration_days ?? 0)),
            ConditionType::Regenerating->value => max(1, (int) ($care->regenerating_duration_days ?? 0)),
            default => 1,
        };
    }

    private function isReusable(Plant $plant, PlantCare $care): bool
    {
        return (bool) ($plant->reusable || $care->reusable || (int) ($care->regenerating_duration_days ?? 0) > 0);
    }

    private function conditionValue(PlantConditionHistory $entry): string
    {
        return $entry->condition_type?->value
            ?? $entry->condition?->value
            ?? (string) ($entry->condition_type ?? $entry->condition);
    }
}
