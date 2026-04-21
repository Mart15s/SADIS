<?php

namespace App\Services;

use App\Enums\AnalysisType;
use App\Enums\ConditionType;
use App\Enums\TaskState;
use App\Enums\TaskType;
use App\Models\GardenOwner;
use App\Models\HarvestRecord;
use App\Models\PlantConditionHistory;
use App\Models\Plot;
use App\Models\RotationHistory;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * @var array<string, int>
     */
    private const CONDITION_SCORES = [
        ConditionType::Dried->value => 0,
        ConditionType::Diseased->value => 1,
        ConditionType::Planted->value => 2,
        ConditionType::Germinating->value => 3,
        ConditionType::Growing->value => 4,
        ConditionType::Regenerating->value => 5,
        ConditionType::Flowering->value => 6,
        ConditionType::Mature->value => 7,
    ];

    public function __construct(
        private readonly PlotSnapshotService $plotSnapshotService,
        private readonly PlantConditionHistoryService $plantConditionHistoryService,
        private readonly HarvestService $harvestService,
    ) {
    }

    /**
     * @param  array<int, string>|string|null  $analysisTypes
     * @return array<string, mixed>
     */
    public function analyzePlot(Plot $plot, ?GardenOwner $owner = null, array|string|null $analysisTypes = null): array
    {
        unset($owner);

        $selectedTypes = $this->normalizeAnalysisTypes($analysisTypes);
        $plot = $this->preparePlot($plot);
        $context = $this->buildContext($plot, $selectedTypes);
        $sections = [];
        $warnings = [];

        foreach ($selectedTypes as $analysisType) {
            [$section, $sectionWarnings] = match ($analysisType) {
                AnalysisType::Planning->value => $this->buildPlanningSection(
                    $plot,
                    $context['planning_snapshots'] ?? collect(),
                    $context['rotation_history'] ?? collect(),
                ),
                AnalysisType::PlantCondition->value => $this->buildPlantConditionSection(
                    $plot,
                    $context['condition_history'] ?? collect(),
                    $context['care_tasks'] ?? collect(),
                ),
                AnalysisType::Harvest->value => $this->buildHarvestSection(
                    $context['harvest_records'] ?? collect(),
                    $context['harvest_tasks'] ?? collect(),
                ),
                default => [$this->emptySection(), []],
            };

            $sections[$analysisType] = $section;
            $warnings = [...$warnings, ...$sectionWarnings];
        }

        if (collect($sections)->every(fn (array $section) => ($section['status'] ?? null) === 'no_data')) {
            $warnings[] = 'No historical data is available for the selected analysis types.';
        }

        return [
            'plot' => $this->serializePlot($plot),
            'selectedAnalysisTypes' => $selectedTypes,
            'sections' => $sections,
            'summary' => $this->buildSummary($plot, $sections, $selectedTypes),
            'generatedAt' => now()->toIso8601String(),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlotSummaryMetrics(Plot $plot): array
    {
        $plantsQuery = $plot->plants();
        $totalPlants = (clone $plantsQuery)->count();
        $diseasedPlants = $this->diseasedPlantsQuery($plot)->count();

        return [
            'total_zones' => $plot->plantZones()->count(),
            'total_plants' => $totalPlants,
            'active_plants_count' => (clone $plantsQuery)
                ->where('condition', '!=', ConditionType::Dried->value)
                ->count(),
            'diseased_plants_count' => $diseasedPlants,
            'shared_users_count' => $plot->accessRights()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTaskMetrics(Plot $plot): array
    {
        $taskQuery = $this->plotTasksQuery($plot);
        $totalTasks = (clone $taskQuery)->count();
        $statusCounts = (clone $taskQuery)
            ->select('state', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('state')
            ->pluck('aggregate', 'state')
            ->map(fn (mixed $count) => (int) $count)
            ->all();

        $typeCounts = (clone $taskQuery)
            ->select('task_type', DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull('task_type')
            ->groupBy('task_type')
            ->pluck('aggregate', 'task_type')
            ->map(fn (mixed $count) => (int) $count)
            ->all();

        $completedTasks = $statusCounts[TaskState::Completed->value] ?? 0;
        $canceledTasks = $statusCounts[TaskState::Canceled->value] ?? 0;
        $pendingTasks = $statusCounts[TaskState::Pending->value] ?? 0;

        return [
            'total_calendars' => $plot->taskCalendars()->count(),
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'canceled_tasks' => $canceledTasks,
            'cancelled_tasks' => $canceledTasks,
            'pending_tasks' => $pendingTasks,
            'completion_ratio' => $totalTasks > 0
                ? round($completedTasks / $totalTasks, 4)
                : 0.0,
            'counts_by_type' => $typeCounts,
        ];
    }

    /**
     * @param  array<int, string>  $selectedTypes
     * @return array<string, mixed>
     */
    private function buildContext(Plot $plot, array $selectedTypes): array
    {
        $context = [];

        if (in_array(AnalysisType::Planning->value, $selectedTypes, true)) {
            $context['planning_snapshots'] = $this->plotSnapshotService->listForPlot($plot, 500);
            $context['rotation_history'] = $this->plotRotationHistoryQuery($plot)
                ->with([
                    'plantZone:id,name,fk_plot_id',
                    'plant:id,name,type,rest_time_days',
                ])
                ->orderBy('from_date')
                ->orderBy('id')
                ->get();
        }

        if (in_array(AnalysisType::PlantCondition->value, $selectedTypes, true)) {
            $context['condition_history'] = $this->plantConditionHistoryService->listForPlot($plot);
            $context['care_tasks'] = $this->plotTasksQuery($plot)
                ->with('plant:id,name')
                ->whereIn('plant_id', $plot->plants()->select('id'))
                ->whereNotIn('task_type', [
                    TaskType::Buy->value,
                    TaskType::Harvest->value,
                ])
                ->orderBy('date')
                ->orderBy('id')
                ->get();
        }

        if (in_array(AnalysisType::Harvest->value, $selectedTypes, true)) {
            $context['harvest_records'] = $this->harvestService->listForPlot($plot);
            $context['harvest_tasks'] = $this->plotTasksQuery($plot)
                ->where('task_type', TaskType::Harvest->value)
                ->orderBy('date')
                ->orderBy('id')
                ->get();
        }

        return $context;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function buildPlanningSection(Plot $plot, Collection $snapshots, Collection $rotationHistory): array
    {
        if ($snapshots->isEmpty() && $rotationHistory->isEmpty()) {
            return [[
                'status' => 'no_data',
                'total_versions' => 0,
                'change_events_count' => 0,
                'actions_breakdown' => [],
                'first_recorded_at' => null,
                'last_recorded_at' => null,
                'plan_change_frequency' => [
                    'change_events_count' => 0,
                    'changes_per_month' => 0.0,
                    'tracked_days' => 0,
                ],
                'zone_season_selections' => [],
                'rotation_history' => [
                    'total_records' => 0,
                    'latest_rotation_date' => null,
                    'zone_participation_counts' => [],
                ],
                'rotation_violations' => [],
                'rotation_violation_count' => 0,
            ], [
                'No planning history data is available for this plot.',
            ]];
        }

        $warnings = [];

        if ($snapshots->isEmpty()) {
            $warnings[] = 'Planning snapshots are missing, so historical plan versions cannot be reconstructed.';
        }

        if ($rotationHistory->isEmpty()) {
            $warnings[] = 'Rotation history records are missing, so rotation violations cannot be evaluated.';
        }

        $historyMetrics = $this->buildPlanningHistoryMetrics($snapshots);
        $rotationMetrics = $this->buildRotationHistoryMetrics($plot, $rotationHistory);
        $rotationViolations = $rotationHistory->isEmpty()
            ? []
            : $this->detectRotationViolations($rotationHistory);

        return [[
            'status' => 'ready',
            'total_versions' => $historyMetrics['total_versions'],
            'change_events_count' => $historyMetrics['change_events_count'],
            'actions_breakdown' => $historyMetrics['actions_breakdown'],
            'first_recorded_at' => $historyMetrics['first_recorded_at'],
            'last_recorded_at' => $historyMetrics['last_recorded_at'],
            'plan_change_frequency' => $historyMetrics['plan_change_frequency'],
            'zone_season_selections' => $snapshots->isEmpty()
                ? []
                : $this->buildZoneSeasonSelections($snapshots),
            'rotation_history' => $rotationMetrics,
            'rotation_violations' => $rotationViolations,
            'rotation_violation_count' => count($rotationViolations),
        ], $warnings];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function buildPlantConditionSection(Plot $plot, Collection $historyEntries, Collection $careTasks): array
    {
        if ($historyEntries->isEmpty()) {
            return [[
                'status' => 'no_data',
                'counts_by_condition' => $this->normalizeConditionCounts([]),
                'history_counts_by_condition' => $this->normalizeConditionCounts([]),
                'disease_ratio' => 0.0,
                'latest_entries_count' => 0,
                'latest_measured_at' => null,
                'plants_with_history_count' => 0,
                'condition_timeline' => [],
                'condition_changes' => [],
                'critical_deterioration_points' => [],
                'critical_deterioration_count' => 0,
                'care_response_trends' => [
                    'improvement_after_care_count' => 0,
                    'total_improvement_events_count' => 0,
                    'improvement_after_care_ratio' => null,
                    'events' => [],
                ],
                'trend_by_plant' => [],
            ], [
                'No plant condition history is available for the selected plot.',
            ]];
        }

        $warnings = [];

        if ($careTasks->isEmpty()) {
            $warnings[] = 'No completed care tasks were found, so post-care improvement trends are limited.';
        }

        $currentCounts = $plot->plants()
            ->select('condition', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('condition')
            ->pluck('aggregate', 'condition')
            ->map(fn (mixed $count) => (int) $count)
            ->all();

        $historyCounts = $historyEntries
            ->groupBy(fn (PlantConditionHistory $entry) => $this->conditionValue($entry))
            ->map(fn (Collection $entries) => $entries->count())
            ->all();

        $plantsWithHistory = $historyEntries
            ->pluck('plant_id')
            ->filter()
            ->unique()
            ->count();

        $latestMeasuredAt = $historyEntries
            ->last()?->measured_at?->toIso8601String();

        $conditionChanges = [];
        $criticalPoints = [];
        $careResponseEvents = [];
        $trendByPlant = [];
        $totalImprovementEvents = 0;

        foreach ($historyEntries->groupBy('plant_id') as $entries) {
            /** @var Collection<int, PlantConditionHistory> $entries */
            $orderedEntries = $entries
                ->sortBy(fn (PlantConditionHistory $entry) => sprintf(
                    '%020d-%020d',
                    $entry->measured_at?->timestamp ?? 0,
                    $entry->id
                ))
                ->values();

            /** @var PlantConditionHistory $firstEntry */
            $firstEntry = $orderedEntries->first();
            /** @var PlantConditionHistory $lastEntry */
            $lastEntry = $orderedEntries->last();
            $initialScore = $this->conditionScore($this->conditionValue($firstEntry));
            $latestScore = $this->conditionScore($this->conditionValue($lastEntry));

            $trendByPlant[] = [
                'plant_id' => $firstEntry->plant_id,
                'plant_name' => $firstEntry->plant?->name,
                'initial_condition' => $this->conditionValue($firstEntry),
                'latest_condition' => $this->conditionValue($lastEntry),
                'direction' => $latestScore > $initialScore
                    ? 'improving'
                    : ($latestScore < $initialScore ? 'deteriorating' : 'stable'),
            ];

            for ($index = 1; $index < $orderedEntries->count(); $index += 1) {
                /** @var PlantConditionHistory $previous */
                $previous = $orderedEntries[$index - 1];
                /** @var PlantConditionHistory $current */
                $current = $orderedEntries[$index];
                $fromCondition = $this->conditionValue($previous);
                $toCondition = $this->conditionValue($current);
                $fromScore = $this->conditionScore($fromCondition);
                $toScore = $this->conditionScore($toCondition);
                $delta = $toScore - $fromScore;

                if ($fromCondition === $toCondition) {
                    continue;
                }

                $change = [
                    'plant_id' => $current->plant_id,
                    'plant_name' => $current->plant?->name,
                    'from_condition' => $fromCondition,
                    'to_condition' => $toCondition,
                    'from_measured_at' => $previous->measured_at?->toIso8601String(),
                    'to_measured_at' => $current->measured_at?->toIso8601String(),
                    'direction' => $delta > 0 ? 'improved' : 'deteriorated',
                    'score_delta' => $delta,
                ];

                $conditionChanges[] = $change;

                if ($this->isCriticalDeterioration($fromScore, $toScore, $toCondition)) {
                    $criticalPoints[] = $change;
                }

                if ($delta > 0) {
                    $totalImprovementEvents += 1;

                    $matchedCareTasks = $careTasks
                        ->filter(function (Task $task) use ($current, $previous): bool {
                            $taskState = $task->state?->value ?? TaskState::normalize($task->status);

                            if ($taskState !== TaskState::Completed->value || ! $task->date) {
                                return false;
                            }

                            return (int) $task->plant_id === (int) $current->plant_id
                                && $task->date->between(
                                    $previous->measured_at?->copy()->startOfDay(),
                                    $current->measured_at?->copy()->endOfDay()
                                );
                        })
                        ->map(fn (Task $task) => [
                            'id' => $task->id,
                            'name' => $task->name,
                            'task_type' => $task->task_type ?? $task->type,
                            'date' => $task->date?->toDateString(),
                        ])
                        ->values()
                        ->all();

                    if ($matchedCareTasks !== []) {
                        $careResponseEvents[] = [
                            ...$change,
                            'care_tasks' => $matchedCareTasks,
                        ];
                    }
                }
            }
        }

        return [[
            'status' => 'ready',
            'counts_by_condition' => $this->normalizeConditionCounts($currentCounts),
            'history_counts_by_condition' => $this->normalizeConditionCounts($historyCounts),
            'disease_ratio' => $plot->plants()->count() > 0
                ? round($this->diseasedPlantsQuery($plot)->count() / $plot->plants()->count(), 4)
                : 0.0,
            'latest_entries_count' => $plantsWithHistory,
            'latest_measured_at' => $latestMeasuredAt,
            'plants_with_history_count' => $plantsWithHistory,
            'condition_timeline' => $historyEntries
                ->map(fn (PlantConditionHistory $entry) => $this->serializeConditionEntry($entry))
                ->values()
                ->all(),
            'condition_changes' => $conditionChanges,
            'critical_deterioration_points' => $criticalPoints,
            'critical_deterioration_count' => count($criticalPoints),
            'care_response_trends' => [
                'improvement_after_care_count' => count($careResponseEvents),
                'total_improvement_events_count' => $totalImprovementEvents,
                'improvement_after_care_ratio' => $totalImprovementEvents > 0
                    ? round(count($careResponseEvents) / $totalImprovementEvents, 4)
                    : null,
                'events' => $careResponseEvents,
            ],
            'trend_by_plant' => $trendByPlant,
        ], $warnings];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function buildHarvestSection(Collection $harvestRecords, Collection $harvestTasks): array
    {
        if ($harvestRecords->isEmpty() && $harvestTasks->isEmpty()) {
            return [[
                'status' => 'no_data',
                'total_harvest_tasks' => 0,
                'completed_harvest_tasks' => 0,
                'canceled_harvest_tasks' => 0,
                'cancelled_harvest_tasks' => 0,
                'pending_harvest_tasks' => 0,
                'latest_harvest_date' => null,
                'plants_with_harvest_tasks_count' => 0,
                'total_records' => 0,
                'total_quantity' => 0.0,
                'plants_with_harvest_records_count' => 0,
                'best_yielding_plants' => [],
                'quantity_by_plant' => [],
                'records_by_period' => [],
                'trend' => [
                    'direction' => 'insufficient_data',
                    'first_period' => null,
                    'last_period' => null,
                    'quantity_delta' => null,
                ],
                'actual_vs_planned_ratio' => null,
            ], [
                'No harvest history is available for the selected plot.',
            ]];
        }

        $warnings = [];

        if ($harvestRecords->isEmpty()) {
            $warnings[] = 'No explicit harvest records are available, so actual yield trends are limited.';
        }

        if ($harvestTasks->isEmpty()) {
            $warnings[] = 'No planned harvest tasks are available, so planned-vs-actual comparison is limited.';
        }

        $statusCounts = $harvestTasks
            ->groupBy(fn (Task $task) => $task->state?->value ?? TaskState::normalize($task->status))
            ->map(fn (Collection $tasks) => $tasks->count())
            ->all();

        $quantityByPlant = $harvestRecords
            ->groupBy('plant_id')
            ->map(function (Collection $records, $plantId): array {
                /** @var HarvestRecord $firstRecord */
                $firstRecord = $records->first();

                return [
                    'plant_id' => (int) $plantId,
                    'plant_name' => $firstRecord->plant?->name,
                    'total_quantity' => round((float) $records->sum(fn (HarvestRecord $record) => (float) $record->quantity), 2),
                ];
            })
            ->sortByDesc('total_quantity')
            ->values()
            ->all();

        $recordsByPeriod = $harvestRecords
            ->groupBy(fn (HarvestRecord $record) => $record->harvested_on?->format('Y-m'))
            ->map(function (Collection $records, string $period): array {
                return [
                    'period' => $period,
                    'total_quantity' => round((float) $records->sum(fn (HarvestRecord $record) => (float) $record->quantity), 2),
                ];
            })
            ->sortBy('period')
            ->values()
            ->all();

        $totalQuantity = round((float) $harvestRecords->sum(fn (HarvestRecord $record) => (float) $record->quantity), 2);
        $latestHarvestDate = $harvestRecords->max('harvested_on') ?? $harvestTasks->max('date');
        $totalHarvestTasks = $harvestTasks->count();

        return [[
            'status' => 'ready',
            'total_harvest_tasks' => $totalHarvestTasks,
            'completed_harvest_tasks' => $statusCounts[TaskState::Completed->value] ?? 0,
            'canceled_harvest_tasks' => $statusCounts[TaskState::Canceled->value] ?? 0,
            'cancelled_harvest_tasks' => $statusCounts[TaskState::Canceled->value] ?? 0,
            'pending_harvest_tasks' => $statusCounts[TaskState::Pending->value] ?? 0,
            'latest_harvest_date' => $latestHarvestDate instanceof Carbon
                ? $latestHarvestDate->toDateString()
                : ($latestHarvestDate?->toDateString() ?? null),
            'plants_with_harvest_tasks_count' => $harvestTasks
                ->pluck('plant_id')
                ->filter()
                ->unique()
                ->count(),
            'total_records' => $harvestRecords->count(),
            'total_quantity' => $totalQuantity,
            'plants_with_harvest_records_count' => $harvestRecords
                ->pluck('plant_id')
                ->filter()
                ->unique()
                ->count(),
            'best_yielding_plants' => array_slice($quantityByPlant, 0, 5),
            'quantity_by_plant' => $quantityByPlant,
            'records_by_period' => $recordsByPeriod,
            'trend' => $this->buildPeriodTrend($recordsByPeriod),
            'actual_vs_planned_ratio' => $totalHarvestTasks > 0
                ? round($harvestRecords->count() / $totalHarvestTasks, 4)
                : null,
        ], $warnings];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @param  array<int, string>  $selectedTypes
     * @return array<string, mixed>
     */
    private function buildSummary(Plot $plot, array $sections, array $selectedTypes): array
    {
        $sectionsWithData = collect($sections)
            ->filter(fn (array $section) => ($section['status'] ?? null) === 'ready')
            ->count();
        $sectionsWithoutData = collect($sections)
            ->filter(fn (array $section) => ($section['status'] ?? null) === 'no_data')
            ->count();

        return array_merge($this->getPlotSummaryMetrics($plot), [
            'selected_sections_count' => count($selectedTypes),
            'sections_with_data_count' => $sectionsWithData,
            'sections_without_data_count' => $sectionsWithoutData,
            'has_actionable_data' => $sectionsWithData > 0,
            'plan_change_frequency_per_month' => data_get($sections, AnalysisType::Planning->value.'.plan_change_frequency.changes_per_month'),
            'critical_condition_points_count' => data_get($sections, AnalysisType::PlantCondition->value.'.critical_deterioration_count'),
            'total_harvest_quantity' => data_get($sections, AnalysisType::Harvest->value.'.total_quantity'),
            'actual_vs_planned_harvest_ratio' => data_get($sections, AnalysisType::Harvest->value.'.actual_vs_planned_ratio'),
        ]);
    }

    private function preparePlot(Plot $plot): Plot
    {
        return $plot->loadMissing([
            'plantZones:id,name,fk_plot_id',
            'plants:id,name,plant_date,condition,disease,type,plant_zone_id,fk_plant_zone_id,fk_plot_id',
            'plants.plantZone:id,name,fk_plot_id',
        ]);
    }

    /**
     * @param  array<int, string>|string|null  $analysisTypes
     * @return array<int, string>
     */
    private function normalizeAnalysisTypes(array|string|null $analysisTypes): array
    {
        if ($analysisTypes === null || $analysisTypes === []) {
            return AnalysisType::values();
        }

        if (is_string($analysisTypes)) {
            return [$analysisTypes];
        }

        return array_values(array_unique($analysisTypes));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlot(Plot $plot): array
    {
        return [
            'id' => $plot->id,
            'name' => $plot->name,
            'city' => $plot->city,
            'plot_size' => $plot->plot_size === null ? null : (float) $plot->plot_size,
            'creation_date' => $plot->creation_date?->toDateString(),
            'description' => $plot->description,
            'share' => (bool) $plot->share,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlanningHistoryMetrics(Collection $snapshots): array
    {
        if ($snapshots->isEmpty()) {
            return [
                'total_versions' => 0,
                'change_events_count' => 0,
                'actions_breakdown' => [],
                'first_recorded_at' => null,
                'last_recorded_at' => null,
                'plan_change_frequency' => [
                    'change_events_count' => 0,
                    'changes_per_month' => 0.0,
                    'tracked_days' => 0,
                ],
            ];
        }

        $timestamps = $snapshots
            ->pluck('created_at')
            ->filter()
            ->map(fn (mixed $timestamp) => Carbon::parse($timestamp))
            ->sort()
            ->values();

        $firstRecordedAt = $timestamps->first();
        $lastRecordedAt = $timestamps->last();
        $trackedDays = $firstRecordedAt && $lastRecordedAt
            ? max(1, $firstRecordedAt->diffInDays($lastRecordedAt) + 1)
            : 0;
        $changeEvents = $snapshots
            ->filter(fn (array $snapshot) => ($snapshot['action'] ?? null) !== 'plot_created')
            ->count();

        return [
            'total_versions' => $snapshots->count(),
            'change_events_count' => $changeEvents,
            'actions_breakdown' => $snapshots
                ->groupBy('action')
                ->map(fn (Collection $entries) => $entries->count())
                ->all(),
            'first_recorded_at' => $firstRecordedAt?->toIso8601String(),
            'last_recorded_at' => $lastRecordedAt?->toIso8601String(),
            'plan_change_frequency' => [
                'change_events_count' => $changeEvents,
                'changes_per_month' => $trackedDays > 0
                    ? round(($changeEvents / $trackedDays) * 30, 2)
                    : 0.0,
                'tracked_days' => $trackedDays,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildZoneSeasonSelections(Collection $snapshots): array
    {
        $selections = [];

        foreach ($snapshots as $snapshot) {
            $snapshotPayload = $snapshot['snapshot'] ?? [];
            $zonesById = collect($snapshotPayload['zones'] ?? [])
                ->keyBy(fn (array $zone) => (string) ($zone['id'] ?? ''));

            foreach ($snapshotPayload['plants'] ?? [] as $plant) {
                $zoneId = $plant['plant_zone_id'] ?? $plant['fk_plant_zone_id'] ?? null;
                $plantName = $plant['name'] ?? null;

                if ($zoneId === null || $plantName === null) {
                    continue;
                }

                $season = $this->resolveSeasonLabel($plant['plant_date'] ?? $snapshot['created_at'] ?? null);
                $key = $season.'|'.$zoneId;

                if (! array_key_exists($key, $selections)) {
                    $selections[$key] = [
                        'season' => $season,
                        'zone_id' => (int) $zoneId,
                        'zone_name' => data_get($zonesById->get((string) $zoneId), 'name'),
                        'plant_names' => [],
                        'snapshot_ids' => [],
                    ];
                }

                $selections[$key]['plant_names'][$plantName] = $plantName;
                $selections[$key]['snapshot_ids'][(int) $snapshot['id']] = (int) $snapshot['id'];
            }
        }

        return collect($selections)
            ->map(function (array $selection): array {
                return [
                    'season' => $selection['season'],
                    'zone_id' => $selection['zone_id'],
                    'zone_name' => $selection['zone_name'],
                    'plant_names' => array_values($selection['plant_names']),
                    'plant_count' => count($selection['plant_names']),
                    'version_count' => count($selection['snapshot_ids']),
                ];
            })
            ->sortBy([
                ['season', 'desc'],
                ['zone_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRotationHistoryMetrics(Plot $plot, Collection $rotationHistory): array
    {
        if ($rotationHistory->isEmpty()) {
            return [
                'total_records' => 0,
                'latest_rotation_date' => null,
                'zone_participation_counts' => [],
            ];
        }

        $zoneNames = $plot->plantZones
            ->pluck('name', 'id');
        $latestRotation = $rotationHistory
            ->sortByDesc(fn (RotationHistory $history) => ($history->to_date ?? $history->from_date)?->timestamp ?? 0)
            ->first();

        return [
            'total_records' => $rotationHistory->count(),
            'latest_rotation_date' => ($latestRotation?->to_date ?? $latestRotation?->from_date)?->toDateString(),
            'zone_participation_counts' => $rotationHistory
                ->groupBy(fn (RotationHistory $history) => (int) ($history->plant_zone_id ?? $history->fk_plant_zone_id))
                ->map(function (Collection $entries, int $zoneId) use ($zoneNames): array {
                    return [
                        'zone_id' => $zoneId,
                        'zone_name' => $zoneNames->get($zoneId),
                        'records_count' => $entries->count(),
                    ];
                })
                ->sortByDesc('records_count')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectRotationViolations(Collection $rotationHistory): array
    {
        $violations = [];

        foreach ($rotationHistory->groupBy(fn (RotationHistory $history) => (int) ($history->plant_zone_id ?? $history->fk_plant_zone_id)) as $zoneEntries) {
            /** @var Collection<int, RotationHistory> $zoneEntries */
            $orderedEntries = $zoneEntries
                ->sortBy(fn (RotationHistory $history) => sprintf(
                    '%020d-%020d',
                    $history->from_date?->timestamp ?? 0,
                    $history->id
                ))
                ->values();

            for ($index = 1; $index < $orderedEntries->count(); $index += 1) {
                /** @var RotationHistory $previous */
                $previous = $orderedEntries[$index - 1];
                /** @var RotationHistory $current */
                $current = $orderedEntries[$index];
                $reasons = [];
                $previousType = $previous->plant?->type?->value ?? $previous->plant?->type;
                $currentType = $current->plant?->type?->value ?? $current->plant?->type;
                $samePlant = $previous->fk_plant_id !== null
                    && (int) $previous->fk_plant_id === (int) $current->fk_plant_id;
                $sameType = $previousType !== null && $previousType === $currentType;
                $gapDays = null;

                if (($previous->to_date ?? $previous->from_date) && $current->from_date) {
                    $gapDays = $current->from_date->diffInDays($previous->to_date ?? $previous->from_date);
                }

                $restDays = max(
                    (int) ($previous->plant?->rest_time_days ?? 0),
                    (int) ($current->plant?->rest_time_days ?? 0),
                );

                if ($samePlant) {
                    $reasons[] = 'The same plant was rotated into the same zone again.';
                }

                if ($sameType) {
                    $reasons[] = 'The same plant type reappeared in the zone without a sufficient rotation change.';
                }

                if ($gapDays !== null && $restDays > 0 && $gapDays < $restDays) {
                    $reasons[] = 'The zone rest interval was shorter than the plant rotation requirement.';
                }

                if ($reasons === []) {
                    continue;
                }

                $violations[] = [
                    'zone_id' => (int) ($current->plant_zone_id ?? $current->fk_plant_zone_id),
                    'zone_name' => $current->plantZone?->name ?? $previous->plantZone?->name,
                    'previous_plant' => $this->serializeRotationPlant($previous),
                    'current_plant' => $this->serializeRotationPlant($current),
                    'previous_from_date' => $previous->from_date?->toDateString(),
                    'previous_to_date' => $previous->to_date?->toDateString(),
                    'current_from_date' => $current->from_date?->toDateString(),
                    'gap_days' => $gapDays,
                    'reasons' => $reasons,
                ];
            }
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeRotationPlant(RotationHistory $history): ?array
    {
        if (! $history->plant) {
            return null;
        }

        return [
            'id' => $history->plant->id,
            'name' => $history->plant->name,
            'type' => $history->plant->type?->value ?? $history->plant->type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConditionEntry(PlantConditionHistory $entry): array
    {
        return [
            'id' => $entry->id,
            'plant_id' => $entry->plant_id,
            'plant_name' => $entry->plant?->name,
            'zone_id' => $entry->plant?->plant_zone_id ?? $entry->plant?->fk_plant_zone_id,
            'zone_name' => $entry->plant?->plantZone?->name,
            'measured_at' => $entry->measured_at?->toIso8601String(),
            'condition' => $this->conditionValue($entry),
            'notes' => $entry->notes,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $recordsByPeriod
     * @return array<string, mixed>
     */
    private function buildPeriodTrend(array $recordsByPeriod): array
    {
        if (count($recordsByPeriod) < 2) {
            return [
                'direction' => 'insufficient_data',
                'first_period' => $recordsByPeriod[0]['period'] ?? null,
                'last_period' => $recordsByPeriod[0]['period'] ?? null,
                'quantity_delta' => null,
            ];
        }

        $firstPeriod = $recordsByPeriod[0];
        $lastPeriod = $recordsByPeriod[count($recordsByPeriod) - 1];
        $delta = round((float) $lastPeriod['total_quantity'] - (float) $firstPeriod['total_quantity'], 2);

        return [
            'direction' => $delta > 0
                ? 'increasing'
                : ($delta < 0 ? 'decreasing' : 'stable'),
            'first_period' => $firstPeriod['period'],
            'last_period' => $lastPeriod['period'],
            'quantity_delta' => $delta,
        ];
    }

    private function resolveSeasonLabel(mixed $date): string
    {
        $resolvedDate = $date ? Carbon::parse($date) : now();
        $month = (int) $resolvedDate->month;
        $season = match (true) {
            $month >= 3 && $month <= 5 => 'spring',
            $month >= 6 && $month <= 8 => 'summer',
            $month >= 9 && $month <= 11 => 'autumn',
            default => 'winter',
        };

        return $resolvedDate->year.'-'.$season;
    }

    private function conditionValue(PlantConditionHistory $entry): string
    {
        return $entry->condition_type?->value
            ?? $entry->condition?->value
            ?? (string) ($entry->condition_type ?? $entry->condition);
    }

    private function conditionScore(?string $condition): int
    {
        return self::CONDITION_SCORES[$condition ?? ''] ?? 0;
    }

    private function isCriticalDeterioration(int $fromScore, int $toScore, string $toCondition): bool
    {
        if (in_array($toCondition, [
            ConditionType::Diseased->value,
            ConditionType::Dried->value,
        ], true)) {
            return true;
        }

        return ($fromScore - $toScore) >= 2;
    }

    private function diseasedPlantsQuery(Plot $plot): HasMany
    {
        return $plot->plants()->where(function (Builder $query) {
            $query
                ->where('disease', true)
                ->orWhere('condition', ConditionType::Diseased->value);
        });
    }

    private function plotTasksQuery(Plot $plot): Builder
    {
        return Task::query()->whereHas('taskCalendar', function (Builder $query) use ($plot) {
            $query->where('plot_id', $plot->id);
        });
    }

    private function plotRotationHistoryQuery(Plot $plot): Builder
    {
        return RotationHistory::query()->where(function (Builder $query) use ($plot) {
            $query
                ->where('fk_plot_id', $plot->id)
                ->orWhere('fk_plot_via_zone', $plot->id);
        });
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function normalizeConditionCounts(array $counts): array
    {
        $normalized = [];

        foreach (ConditionType::cases() as $condition) {
            $normalized[$condition->value] = (int) ($counts[$condition->value] ?? 0);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySection(): array
    {
        return [
            'status' => 'no_data',
        ];
    }
}
