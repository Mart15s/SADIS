<?php

namespace App\Services\Plot;

use App\Models\GardenOwner;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\RotationHistory;
use App\Models\RotationPlanDraft;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RotationPlannerService
{
    public function __construct(
        private readonly CropRotationClassifier $cropRotationClassifier,
    ) {
    }

    public function evaluatePlot(Plot $plot, ?string $planningDate = null): array
    {
        $planningDate = $this->normalizePlanningDate($planningDate);
        $preparedPlot = $this->preparePlot($plot);
        $plan = $this->buildPlan($preparedPlot, $planningDate);

        return [
            'planning_date' => $planningDate->toDateString(),
            'status' => $plan['status'],
            'summary' => $plan['summary'],
            'plants' => $plan['plants'],
        ];
    }

    public function evaluatePlacement(Plot $plot, Plant $plant, PlantZone $targetZone, ?string $fromDate = null): array
    {
        $planningDate = $this->normalizePlanningDate($fromDate);
        $preparedPlot = $this->preparePlot($plot);
        $plant = $preparedPlot->plants->firstWhere('id', $plant->id) ?? $plant->fresh(['plantZone', 'catalogPlant.plantCare']);
        $targetZone = $preparedPlot->plantZones->firstWhere('id', $targetZone->id) ?? $targetZone->fresh(['plants.catalogPlant.plantCare', 'rotationHistory.plant']);

        $candidate = $this->evaluateZoneCandidate($preparedPlot, $plant, $targetZone, $planningDate, []);
        $alternatives = $this->validAlternatives($preparedPlot, $plant, $planningDate, [], $targetZone->id);
        $fallbackSolutions = $alternatives->isNotEmpty()
            ? []
            : $this->buildFallbackSolutions($preparedPlot, $plant, collect([$candidate]), $planningDate);

        return [
            'planning_date' => $planningDate->toDateString(),
            'plant' => $this->plantSummary($plant),
            'current_zone' => $this->zoneSummary($plant->plantZone),
            'target_zone' => array_merge($candidate, [
                'id' => $candidate['zone_id'],
                'name' => $candidate['zone_name'],
            ]),
            'alternatives' => $alternatives->take(3)->values()->all(),
            'fallback_solutions' => $fallbackSolutions,
        ];
    }

    public function createDraft(Plot $plot, ?string $planningDate = null, ?GardenOwner $owner = null): RotationPlanDraft
    {
        $planningDate = $this->normalizePlanningDate($planningDate);
        $preparedPlot = $this->preparePlot($plot);
        $plan = $this->buildPlan($preparedPlot, $planningDate);

        return RotationPlanDraft::query()->create([
            'plot_id' => $preparedPlot->id,
            'garden_owner_id' => $owner?->id,
            'planning_date' => $planningDate->toDateString(),
            'plan' => $plan,
        ]);
    }

    public function confirmDraft(
        Plot $plot,
        RotationPlanDraft $draft,
        PlotSnapshotService $plotSnapshotService,
        ?GardenOwner $owner = null
    ): array {
        $preparedPlot = $this->preparePlot($plot);
        $plan = $draft->plan ?? [];
        $status = (string) ($plan['status'] ?? 'needs_adjustment');

        abort_if($status !== 'ready', 422, 'The generated rotation scheme still has unresolved plants and cannot be confirmed.');

        $planningDate = $this->normalizePlanningDate($draft->planning_date?->toDateString());
        $assignments = collect($plan['plants'] ?? [])
            ->filter(fn (array $entry) => filled($entry['selected_target_zone']['zone_id'] ?? null))
            ->mapWithKeys(fn (array $entry) => [
                (int) $entry['plant']['id'] => (int) $entry['selected_target_zone']['zone_id'],
            ]);

        $assignmentMap = $assignments->all();

        foreach (($plan['plants'] ?? []) as $entry) {
            abort_if(
                blank($entry['selected_target_zone']['zone_id'] ?? null)
                    || ! (bool) ($entry['selected_target_zone']['is_eligible'] ?? false)
                    || filled($entry['selected_target_zone']['hard_blocking_reasons'] ?? []),
                422,
                'The generated rotation scheme contains an unsafe or unresolved plant assignment.'
            );

            $plant = $preparedPlot->plants->firstWhere('id', (int) ($entry['plant']['id'] ?? 0));
            $zone = $preparedPlot->plantZones->firstWhere('id', (int) ($entry['selected_target_zone']['zone_id'] ?? 0));

            abort_if(! $plant || ! $zone, 422, 'The generated rotation scheme references a missing plant or zone.');

            $freshCandidate = $this->evaluateZoneCandidate($preparedPlot, $plant, $zone, $planningDate, $assignmentMap);

            abort_if(! $freshCandidate['is_eligible'], 422, 'The generated rotation scheme is no longer valid for the current plot data.');
        }

        $changedAssignments = $preparedPlot->plants
            ->filter(function (Plant $plant) use ($assignments): bool {
                $currentZoneId = (int) ($plant->plant_zone_id ?? $plant->fk_plant_zone_id);
                $targetZoneId = (int) ($assignments[$plant->id] ?? $currentZoneId);

                return $targetZoneId !== 0 && $targetZoneId !== $currentZoneId;
            })
            ->values();

        return DB::transaction(function () use (
            $preparedPlot,
            $draft,
            $plotSnapshotService,
            $owner,
            $plan,
            $planningDate,
            $assignments,
            $changedAssignments
        ): array {
            $plotSnapshotService->capture(
                $preparedPlot->fresh(['plantZones.plants', 'plants.plantZone']),
                'rotation_plan_before_confirm',
                $owner,
                [
                    'rotation_plan_draft_id' => $draft->id,
                    'rotation_plan' => $plan,
                ]
            );

            $touchedTargetZoneIds = [];

            foreach ($changedAssignments as $plant) {
                $targetZoneId = (int) $assignments[$plant->id];
                $currentZoneId = (int) ($plant->plant_zone_id ?? $plant->fk_plant_zone_id);

                RotationHistory::query()
                    ->where('fk_plot_id', $preparedPlot->id)
                    ->where('fk_plant_id', $plant->id)
                    ->where('fk_plant_zone_id', $currentZoneId)
                    ->whereNull('to_date')
                    ->update([
                        'to_date' => $planningDate->subDay()->toDateString(),
                    ]);

                $plant->update([
                    'plant_zone_id' => $targetZoneId,
                    'fk_plant_zone_id' => $targetZoneId,
                ]);

                RotationHistory::query()->create([
                    'from_date' => $planningDate->toDateString(),
                    'to_date' => null,
                    'plant_zone_id' => $targetZoneId,
                    'fk_plot_id' => $preparedPlot->id,
                    'fk_plant_zone_id' => $targetZoneId,
                    'fk_plot_via_zone' => $preparedPlot->id,
                    'fk_plant_id' => $plant->id,
                ]);

                $touchedTargetZoneIds[] = $targetZoneId;
            }

            PlantZone::query()
                ->whereIn('id', array_values(array_unique($touchedTargetZoneIds)))
                ->get()
                ->each(function (PlantZone $zone) use ($planningDate): void {
                    $zone->update([
                        'rotation_stage' => $zone->rotation_stage + 1,
                        'last_planting_date' => $planningDate->toDateString(),
                    ]);
                });

            $updatedPlot = $preparedPlot->fresh([
                'plantZones.plants',
                'plants.plantZone',
                'plants.catalogPlant.plantCare',
                'rotationHistory.plantZone',
                'rotationHistory.plant',
            ]);

            $plotSnapshotService->capture(
                $updatedPlot,
                'rotation_plan_confirmed',
                $owner,
                [
                    'rotation_plan_draft_id' => $draft->id,
                    'rotation_plan' => $plan,
                    'changed_plant_ids' => $changedAssignments->pluck('id')->all(),
                ]
            );

            $draft->delete();

            return [
                'planning_date' => $planningDate->toDateString(),
                'changed_plant_ids' => $changedAssignments->pluck('id')->all(),
                'plants' => $updatedPlot->plants,
                'plant_zones' => $updatedPlot->plantZones,
                'rotation_history' => $updatedPlot->rotationHistory
                    ->sortByDesc(fn (RotationHistory $history) => $history->from_date?->timestamp ?? 0)
                    ->values(),
            ];
        });
    }

    public function rejectDraft(RotationPlanDraft $draft): void
    {
        $draft->delete();
    }

    private function preparePlot(Plot $plot): Plot
    {
        $plot->loadMissing([
            'plantZones.plants.catalogPlant.plantCare',
            'plantZones.rotationHistory.plant',
            'plants.plantZone',
            'plants.catalogPlant.plantCare',
            'rotationHistory.plantZone',
            'rotationHistory.plant',
        ]);

        return $plot;
    }

    private function buildPlan(Plot $plot, CarbonImmutable $planningDate): array
    {
        $plantOrder = $this->orderPlantsForPlanning($plot, $planningDate);
        $selectedAssignments = $this->chooseDraftAssignments($plot, $plantOrder, $planningDate);
        $visibleAssignments = [];
        $planPlants = [];

        foreach ($plantOrder as $plant) {
            $candidates = $plot->plantZones
                ->map(fn (PlantZone $zone) => $this->evaluateZoneCandidate(
                    $plot,
                    $plant,
                    $zone,
                    $planningDate,
                    $visibleAssignments
                ))
                ->sortByDesc('score')
                ->values();

            $alternatives = $candidates
                ->filter(fn (array $candidate) => $candidate['is_eligible'])
                ->values();
            $selectedZoneId = (int) ($selectedAssignments[$plant->id] ?? 0);
            $selectedTarget = $selectedZoneId === 0
                ? null
                : $candidates->first(fn (array $candidate) => (int) $candidate['zone_id'] === $selectedZoneId && $candidate['is_eligible']);

            if ($selectedTarget) {
                $visibleAssignments[$plant->id] = $selectedTarget['zone_id'];
            }

            $planPlants[] = [
                'plant' => $this->plantSummary($plant),
                'current_zone' => $this->zoneSummary($plant->plantZone),
                'selected_target_zone' => $selectedTarget,
                'candidate_zones' => $candidates->all(),
                'alternatives' => $alternatives->take(3)->values()->all(),
                'fallback_solutions' => $selectedTarget
                    ? []
                    : $this->buildFallbackSolutions($plot, $plant, $candidates, $planningDate),
            ];
        }

        $readyAssignments = collect($planPlants)->filter(fn (array $entry) => filled($entry['selected_target_zone']['zone_id'] ?? null));
        $unresolvedPlants = collect($planPlants)->reject(fn (array $entry) => filled($entry['selected_target_zone']['zone_id'] ?? null));

        return [
            'status' => $unresolvedPlants->isEmpty() ? 'ready' : 'needs_adjustment',
            'summary' => [
                'plant_count' => count($planPlants),
                'assigned_plant_count' => $readyAssignments->count(),
                'unresolved_plant_count' => $unresolvedPlants->count(),
                'blocked_plant_count' => $unresolvedPlants->count(),
                'generated_at' => $planningDate->startOfDay()->toIso8601String(),
            ],
            'plants' => $planPlants,
        ];
    }

    /**
     * @param  Collection<int, Plant>  $plantOrder
     * @return array<int, int>
     */
    private function chooseDraftAssignments(Plot $plot, Collection $plantOrder, CarbonImmutable $planningDate): array
    {
        $plants = $plantOrder->values();
        $best = [
            'assignments' => [],
            'assigned_count' => -1,
            'score' => PHP_INT_MIN,
        ];
        $visitedNodes = 0;
        $maxNodes = 8000;

        $search = function (int $index, array $assignments, int $assignedCount, int $score) use (
            &$search,
            &$best,
            &$visitedNodes,
            $maxNodes,
            $plants,
            $plot,
            $planningDate
        ): void {
            $visitedNodes += 1;

            if ($visitedNodes > $maxNodes) {
                return;
            }

            $remaining = $plants->count() - $index;
            if ($assignedCount + $remaining < $best['assigned_count']) {
                return;
            }

            if ($index >= $plants->count()) {
                if (
                    $assignedCount > $best['assigned_count']
                    || ($assignedCount === $best['assigned_count'] && $score > $best['score'])
                ) {
                    $best = [
                        'assignments' => $assignments,
                        'assigned_count' => $assignedCount,
                        'score' => $score,
                    ];
                }

                return;
            }

            /** @var Plant $plant */
            $plant = $plants[$index];
            $candidates = $plot->plantZones
                ->map(fn (PlantZone $zone) => $this->evaluateZoneCandidate($plot, $plant, $zone, $planningDate, $assignments))
                ->filter(fn (array $candidate) => $candidate['is_eligible'])
                ->sortByDesc('score')
                ->take(6)
                ->values();

            foreach ($candidates as $candidate) {
                $nextAssignments = $assignments;
                $nextAssignments[$plant->id] = (int) $candidate['zone_id'];
                $search($index + 1, $nextAssignments, $assignedCount + 1, $score + (int) $candidate['score']);
            }

            $search($index + 1, $assignments, $assignedCount, $score);
        };

        $search(0, [], 0, 0);

        return $best['assignments'];
    }

    /**
     * @param  array<int, int>  $selectedAssignments
     * @return array<string, mixed>
     */
    private function evaluateZoneCandidate(
        Plot $plot,
        Plant $plant,
        PlantZone $targetZone,
        CarbonImmutable $planningDate,
        array $selectedAssignments
    ): array {
        $positiveReasons = [];
        $hardBlockingReasons = [];
        $softWarnings = [];
        $currentZoneId = (int) ($plant->plant_zone_id ?? $plant->fk_plant_zone_id);
        $currentZone = $plant->plantZone;
        $occupancy = $this->draftOccupancyForZone($plot, $targetZone, $plant, $selectedAssignments);
        $tentativePlants = $occupancy['tentative'];
        $currentOccupants = $occupancy['current'];
        $draftAssignedOccupants = $occupancy['draft_assigned'];
        $remainingCapacity = $this->remainingZoneCapacity($targetZone, $tentativePlants, $plant);
        $plantProfile = $this->cropRotationClassifier->profileForPlant($plant);
        $rotationConflict = null;
        $passedReasons =& $positiveReasons;
        $blockingReasons =& $hardBlockingReasons;

        if (! (bool) ($plantProfile['has_rotation_data'] ?? false)) {
            $softWarnings[] = 'Rotation family or group data is missing, so family-based rotation checks are neutral for this plant.';
        }

        if ($currentZoneId !== 0 && $currentZoneId === (int) $targetZone->id) {
            $hardBlockingReasons[] = 'Target zone is the same as the current plant zone.';
        }

        if ($currentZoneId !== 0 && $currentZoneId === (int) $targetZone->id && ! (bool) $plant->reusable) {
            $blockingReasons[] = 'TikslinÄ— zona sutampa su dabartine augalo zona, todÄ—l tai nÄ—ra tinkama rotacija.';
        } else {
            $passedReasons[] = $currentZoneId === (int) $targetZone->id
                ? 'Augalas gali likti dabartinÄ—je zonoje tik todÄ—l, kad paÅ¾ymÄ—tas kaip daugiametis ar pakartotinai naudojamas.'
                : 'TikslinÄ— zona skiriasi nuo dabartinÄ—s zonos, todÄ—l atitinka bazinÄÆ rotacijos perkÄ—limo principÄ….';
        }

        if ($remainingCapacity < 0) {
            $blockingReasons[] = 'TikslinÄ—je zonoje nepakanka vietos Åiam augalui.';
        } else {
            $passedReasons[] = 'TikslinÄ—je zonoje pakanka vietos Åiam augalui.';
        }

        $sameNameConflict = $currentOccupants->contains(function (Plant $zonePlant) use ($plant): bool {
            return Str::lower((string) $zonePlant->name) === Str::lower((string) $plant->name);
        });

        if ($sameNameConflict) {
            $blockingReasons[] = 'TikslinÄ—je zonoje jau yra toks pats augalas.';
        } else {
            $passedReasons[] = 'TikslinÄ—je zonoje nÄ—ra tokio paties augalo konflikto.';
        }

        $sameNameDraftConflict = $draftAssignedOccupants->filter(function (Plant $zonePlant) use ($plant): bool {
            return Str::lower((string) $zonePlant->name) === Str::lower((string) $plant->name);
        });

        if (! $sameNameConflict && $sameNameDraftConflict->isNotEmpty()) {
            $blockingReasons[] = 'This rotation draft already assigns '.$this->plantNames($sameNameDraftConflict).' to this zone, creating a same-plant conflict.';
        }

        $plantType = $plant->type?->value ?? $plant->type;
        $sameTypeConflict = $currentOccupants->contains(function (Plant $zonePlant) use ($plantType): bool {
            $zonePlantType = $zonePlant->type?->value ?? $zonePlant->type;

            return $plantType !== null && $zonePlantType !== null && $zonePlantType === $plantType;
        });

        if ($sameTypeConflict) {
            $blockingReasons[] = 'TikslinÄ—je zonoje jau yra to paties tipo augalÅ³ konfliktas.';
        } else {
            $passedReasons[] = 'TikslinÄ—je zonoje nÄ—ra to paties tipo augalÅ³ konflikto.';
        }

        $sameTypeDraftConflict = $draftAssignedOccupants->filter(function (Plant $zonePlant) use ($plantType): bool {
            $zonePlantType = $zonePlant->type?->value ?? $zonePlant->type;

            return $plantType !== null && $zonePlantType !== null && $zonePlantType === $plantType;
        });

        if (! $sameTypeConflict && $sameTypeDraftConflict->isNotEmpty()) {
            $blockingReasons[] = 'There is no current same-type conflict in this zone, but this rotation draft already assigns '.$this->plantNames($sameTypeDraftConflict).' there, creating a same-type conflict.';
        }

        $sameRotationGroupConflict = $currentOccupants->contains(function (Plant $zonePlant) use ($plantProfile): bool {
            return $this->profilesConflict($plantProfile, $this->cropRotationClassifier->profileForPlant($zonePlant));
        });

        if ($sameRotationGroupConflict) {
            $hardBlockingReasons[] = 'Target zone currently has a same family or rotation group conflict.';
        }

        $sameRotationGroupDraftConflict = $draftAssignedOccupants->filter(function (Plant $zonePlant) use ($plantProfile): bool {
            return $this->profilesConflict($plantProfile, $this->cropRotationClassifier->profileForPlant($zonePlant));
        });

        if (! $sameRotationGroupConflict && $sameRotationGroupDraftConflict->isNotEmpty()) {
            $hardBlockingReasons[] = 'This rotation draft already assigns '.$this->plantNames($sameRotationGroupDraftConflict).' to this otherwise available zone, creating a same family or rotation group conflict.';
        }

        $rotationConflict = $this->recentRotationConflict($targetZone, $plant, $planningDate, $plantProfile);
        if ($rotationConflict !== null) {
            $blockingReasons[] = $rotationConflict['message'];
        } else {
            $passedReasons[] = 'TikslinÄ—je zonoje nÄ—ra per neseniai sodinto to paties augalo ar tipo rotacijos konflikto.';
        }

        $restConflict = $this->restIntervalConflict($targetZone, $plant, $planningDate);
        if ($restConflict !== null) {
            $blockingReasons[] = $restConflict;
        } else {
            $passedReasons[] = 'TikslinÄ— zona atitinka minimalÅ³ poilsio intervalÄ….';
        }

        $soilCompatibility = $this->soilCompatibilityForZone($plant, $targetZone);
        if ($soilCompatibility === false) {
            $blockingReasons[] = 'TikslinÄ—s zonos dirvoÅ¾emis neatitinka augalo prieÅ¾iÅ«ros profilio signalÅ³.';
        } elseif ($soilCompatibility === true) {
            $passedReasons[] = 'TikslinÄ—s zonos dirvoÅ¾emis atitinka augalo prieÅ¾iÅ«ros profilio signalus.';
        }

        if ($soilCompatibility === null) {
            $softWarnings[] = 'Soil compatibility data is incomplete.';
        }

        $nutrientReason = $this->nutrientSequenceReason($targetZone, $plantProfile);
        if ($nutrientReason['positive'] !== null) {
            $positiveReasons[] = $nutrientReason['positive'];
        }
        if ($nutrientReason['warning'] !== null) {
            $softWarnings[] = $nutrientReason['warning'];
        }

        $hardBlockingReasons = $this->moveBroadTypeConflictsToWarnings($hardBlockingReasons, $softWarnings);
        $verdict = $hardBlockingReasons === [] ? 'valid' : 'invalid';
        $score = $this->calculateScore($positiveReasons, $softWarnings, $remainingCapacity, $targetZone, $plant, $plantProfile);

        return [
            'zone_id' => $targetZone->id,
            'zone_name' => $targetZone->name,
            'verdict' => $verdict,
            'is_eligible' => $verdict === 'valid',
            'score' => $verdict === 'valid' ? $score : min(0, $score),
            'status' => $verdict === 'valid' ? 'recommended' : 'not_recommended',
            'suitability' => $verdict === 'valid' ? 'recommended' : 'not_recommended',
            'reason' => $hardBlockingReasons[0] ?? $positiveReasons[0] ?? null,
            'warning' => $hardBlockingReasons[0] ?? $softWarnings[0] ?? null,
            'remaining_capacity' => round($remainingCapacity, 2),
            'is_current_zone' => $currentZoneId !== 0 && $currentZoneId === (int) $targetZone->id,
            'current_zone_name' => $currentZone?->name,
            'rotation_profile' => $plantProfile,
            'conflicting_previous_plant' => $rotationConflict['conflicting_previous_plant'] ?? null,
            'previous_season' => $rotationConflict['previous_season'] ?? null,
            'previous_year' => $rotationConflict['previous_year'] ?? null,
            'current_occupants' => $currentOccupants->map(fn (Plant $zonePlant) => $this->plantSummary($zonePlant))->values()->all(),
            'draft_assigned_occupants' => $draftAssignedOccupants->map(fn (Plant $zonePlant) => $this->plantSummary($zonePlant))->values()->all(),
            'positive_reasons' => $positiveReasons,
            'soft_warnings' => array_values(array_unique($softWarnings)),
            'hard_blocking_reasons' => array_values(array_unique($hardBlockingReasons)),
            'passed_reasons' => $positiveReasons,
            'blocking_reasons' => array_values(array_unique($hardBlockingReasons)),
        ];
    }

    /**
     * @param  array<int, int>  $selectedAssignments
     * @return Collection<int, array<string, mixed>>
     */
    private function validAlternatives(
        Plot $plot,
        Plant $plant,
        CarbonImmutable $planningDate,
        array $selectedAssignments,
        ?int $excludeZoneId = null
    ): Collection {
        return $plot->plantZones
            ->filter(fn (PlantZone $zone) => $excludeZoneId === null || (int) $zone->id !== (int) $excludeZoneId)
            ->map(fn (PlantZone $zone) => $this->evaluateZoneCandidate($plot, $plant, $zone, $planningDate, $selectedAssignments))
            ->filter(fn (array $candidate) => $candidate['is_eligible'])
            ->sortByDesc('score')
            ->values();
    }

    /**
     * @param  array<int, int>  $selectedAssignments
     * @return array{current: Collection<int, Plant>, draft_assigned: Collection<int, Plant>, tentative: Collection<int, Plant>}
     */
    private function draftOccupancyForZone(
        Plot $plot,
        PlantZone $targetZone,
        Plant $evaluatedPlant,
        array $selectedAssignments
    ): array {
        $current = collect();
        $draftAssigned = collect();

        foreach ($plot->plants as $candidate) {
            if ((int) $candidate->id === (int) $evaluatedPlant->id) {
                continue;
            }

            $candidateCurrentZoneId = (int) ($candidate->plant_zone_id ?? $candidate->fk_plant_zone_id);
            $hasDraftAssignment = array_key_exists($candidate->id, $selectedAssignments);
            $assignedZoneId = (int) ($selectedAssignments[$candidate->id] ?? $candidateCurrentZoneId);

            if ($assignedZoneId !== (int) $targetZone->id) {
                continue;
            }

            if ($hasDraftAssignment && $candidateCurrentZoneId !== (int) $targetZone->id) {
                $draftAssigned->push($candidate);
            } else {
                $current->push($candidate);
            }
        }

        return [
            'current' => $current->values(),
            'draft_assigned' => $draftAssigned->values(),
            'tentative' => $current->merge($draftAssigned)->values(),
        ];
    }

    /**
     * @param  array<int, int>  $selectedAssignments
     * @return Collection<int, Plant>
     */
    private function tentativePlantsForZone(
        Plot $plot,
        PlantZone $targetZone,
        Plant $evaluatedPlant,
        array $selectedAssignments
    ): Collection {
        return $plot->plants
            ->filter(function (Plant $candidate) use ($targetZone, $evaluatedPlant, $selectedAssignments): bool {
                if ((int) $candidate->id === (int) $evaluatedPlant->id) {
                    return false;
                }

                $assignedZoneId = (int) ($selectedAssignments[$candidate->id] ?? ($candidate->plant_zone_id ?? $candidate->fk_plant_zone_id));

                return $assignedZoneId === (int) $targetZone->id;
            })
            ->values();
    }

    /**
     * @return Collection<int, Plant>
     */
    private function orderPlantsForPlanning(Plot $plot, CarbonImmutable $planningDate): Collection
    {
        return $plot->plants
            ->map(function (Plant $plant) use ($plot, $planningDate): array {
                $validCandidateCount = $plot->plantZones
                    ->filter(fn (PlantZone $zone) => $this->evaluateZoneCandidate($plot, $plant, $zone, $planningDate, [])['is_eligible'])
                    ->count();

                return [
                    'plant' => $plant,
                    'valid_candidate_count' => $validCandidateCount,
                    'plant_size' => (float) ($plant->plant_size ?? 0),
                ];
            })
            ->sortBy([
                ['valid_candidate_count', 'asc'],
                ['plant_size', 'desc'],
                [fn (array $entry) => $entry['plant']->id, 'asc'],
            ])
            ->pluck('plant')
            ->values();
    }

    /**
     * @param  Collection<int, Plant>  $tentativePlants
     */
    private function remainingZoneCapacity(PlantZone $zone, Collection $tentativePlants, Plant $incomingPlant): float
    {
        $zoneSize = (float) ($zone->zone_size ?? 0);
        $occupiedSize = $tentativePlants->sum(fn (Plant $zonePlant) => (float) ($zonePlant->plant_size ?? 0));
        $incomingPlantSize = (float) ($incomingPlant->plant_size ?? 0);

        return $zoneSize - $occupiedSize - $incomingPlantSize;
    }

    /**
     * @param  array<string, mixed>  $plantProfile
     * @return array{message: string, conflicting_previous_plant: array<string, mixed>|null, previous_season: string|null, previous_year: int|null}|null
     */
    private function recentRotationConflict(PlantZone $targetZone, Plant $plant, CarbonImmutable $planningDate, array $plantProfile): ?array
    {
        $restWindow = max(365 * 3, (int) ($plant->rest_time_days ?? 0));
        $cutoffDate = $planningDate->subDays($restWindow);
        $plantName = $this->normalizedPlantName($plant);

        $recentPlantRotation = $targetZone->rotationHistory
            ->filter(function (RotationHistory $history) use ($plant, $plantName, $cutoffDate): bool {
                $referenceDate = $history->to_date ?? $history->from_date;
                $historyPlantName = $history->plant ? $this->normalizedPlantName($history->plant) : null;

                return ((int) $history->fk_plant_id === (int) $plant->id
                    || ($plantName !== '' && $historyPlantName === $plantName))
                    && $referenceDate !== null
                    && $referenceDate->greaterThanOrEqualTo($cutoffDate);
            })
            ->sortByDesc(fn (RotationHistory $history) => ($history->to_date ?? $history->from_date)?->timestamp ?? 0)
            ->first();

        if ($recentPlantRotation) {
            return $this->rotationConflictPayload(
                'Tikslinėje zonoje tas pats augalas buvo sodintas per neseniai pagal rotacijos istoriją.',
                $recentPlantRotation
            );
        }

        $recentTypeRotation = $targetZone->rotationHistory
            ->filter(function (RotationHistory $history) use ($cutoffDate, $plantProfile): bool {
                $referenceDate = $history->to_date ?? $history->from_date;
                $historyProfile = $history->plant
                    ? $this->cropRotationClassifier->profileForPlant($history->plant)
                    : ['family' => null, 'group' => null];

                return $this->profilesConflict($plantProfile, $historyProfile)
                    && $referenceDate !== null
                    && $referenceDate->greaterThanOrEqualTo($cutoffDate);
            })
            ->sortByDesc(fn (RotationHistory $history) => ($history->to_date ?? $history->from_date)?->timestamp ?? 0)
            ->first();

        if ($recentTypeRotation) {
            return $this->rotationConflictPayload(
                'Tikslinėje zonoje tos pačios šeimos arba rotacijos grupės augalai buvo sodinti per neseniai pagal pasėlių kaitos taisykles.',
                $recentTypeRotation
            );
        }

        return null;
    }

    private function restIntervalConflict(PlantZone $targetZone, Plant $plant, CarbonImmutable $planningDate): ?string
    {
        $restDays = (int) ($plant->rest_time_days ?? 0);

        if ($restDays <= 0 || ! $targetZone->last_planting_date) {
            return null;
        }

        $daysSinceLastPlanting = CarbonImmutable::instance($targetZone->last_planting_date)->diffInDays($planningDate);

        if ($daysSinceLastPlanting < $restDays) {
            return 'TikslinÄ— zona dar nÄ—ra iÅlaukusi minimalaus poilsio intervalo.';
        }

        return null;
    }

    private function soilCompatibilityForZone(Plant $plant, PlantZone $targetZone): ?bool
    {
        $care = $this->resolvePlantCare($plant);
        $soilSignal = Str::lower(trim(implode(' ', array_filter([
            $care?->conditions,
            $care?->description,
            $care?->plant_name,
        ]))));

        if ($soilSignal === '' || ! $targetZone->soil_type) {
            return null;
        }

        $keywords = match ($targetZone->soil_type->value ?? $targetZone->soil_type) {
            'clay' => ['clay'],
            'sandy' => ['sandy', 'well-drained', 'well drained'],
            'peaty' => ['peat', 'peaty'],
            'rocky' => ['rocky', 'gravel'],
            'greasy' => ['loam', 'fertile', 'rich soil', 'rich'],
            default => [],
        };

        if ($keywords === []) {
            return null;
        }

        $positiveMatch = collect($keywords)->contains(fn (string $keyword) => Str::contains($soilSignal, $keyword));

        if (! $positiveMatch) {
            return null;
        }

        $negativeSignals = [
            'clay' => ['sandy only', 'avoid clay'],
            'sandy' => ['avoid sand', 'not sandy'],
            'peaty' => ['avoid peat'],
            'rocky' => ['avoid rocky'],
            'greasy' => ['avoid rich soil'],
        ];

        $zoneKey = $targetZone->soil_type->value ?? $targetZone->soil_type;
        $hasNegativeMatch = collect($negativeSignals[$zoneKey] ?? [])
            ->contains(fn (string $keyword) => Str::contains($soilSignal, $keyword));

        return ! $hasNegativeMatch;
    }

    /**
     * @param  array<string, mixed>  $firstProfile
     * @param  array<string, mixed>  $secondProfile
     */
    private function profilesConflict(array $firstProfile, array $secondProfile): bool
    {
        $firstFamily = $firstProfile['family'] ?? null;
        $secondFamily = $secondProfile['family'] ?? null;

        if ($firstFamily !== null && $secondFamily !== null && $firstFamily === $secondFamily) {
            return true;
        }

        $firstGroup = $firstProfile['group'] ?? null;
        $secondGroup = $secondProfile['group'] ?? null;

        return $firstGroup !== null && $secondGroup !== null && $firstGroup === $secondGroup;
    }

    /**
     * @param  array<string, mixed>  $plantProfile
     * @return array{positive: string|null, warning: string|null}
     */
    private function nutrientSequenceReason(PlantZone $targetZone, array $plantProfile): array
    {
        if (($plantProfile['nutrient_role'] ?? null) !== 'heavy_feeder') {
            return [
                'positive' => 'Plant nutrient demand is not marked as heavy feeding.',
                'warning' => null,
            ];
        }

        $latestHistory = $targetZone->rotationHistory
            ->filter(fn (RotationHistory $history): bool => $history->plant !== null)
            ->sortByDesc(fn (RotationHistory $history) => ($history->to_date ?? $history->from_date)?->timestamp ?? 0)
            ->first();

        if (! $latestHistory?->plant) {
            return [
                'positive' => null,
                'warning' => 'No previous crop data is available to verify nutrient sequence.',
            ];
        }

        $previousProfile = $this->cropRotationClassifier->profileForPlant($latestHistory->plant);

        if (($previousProfile['nutrient_role'] ?? null) === 'nitrogen_restoring') {
            return [
                'positive' => 'Heavy feeder follows a nitrogen-restoring crop.',
                'warning' => null,
            ];
        }

        return [
            'positive' => null,
            'warning' => 'Nutrient sequence is weaker because the previous crop was not nitrogen-restoring.',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array<int, string>
     */
    private function buildFallbackSolutions(
        Plot $plot,
        Plant $plant,
        Collection $candidates,
        CarbonImmutable $planningDate
    ): array {
        $bestBlockedZone = $candidates
            ->sortByDesc('score')
            ->first();
        $solutions = [];

        if ($bestBlockedZone && ($bestBlockedZone['remaining_capacity'] ?? 0) < 0) {
            $solutions[] = 'Padidinkite arba atlaisvinkite zonÄ… "'.$bestBlockedZone['zone_name'].'" prieÅ planuodami rotacijÄ….';
        }

        if ($candidates->contains(fn (array $candidate) => collect($candidate['blocking_reasons'] ?? [])->contains(
            fn (string $reason) => Str::contains($reason, 'poilsio interval')
        ))) {
            $solutions[] = 'AtidÄ—kite rotacijÄ… vÄ—lesnei datai, kad zonos spÄ—tÅ³ iÅlaukti poilsio intervalÄ….';
        }

        if ($candidates->contains(fn (array $candidate) => collect($candidate['blocking_reasons'] ?? [])->contains(
            fn (string $reason) => Str::contains($reason, 'to paties tipo')
        ))) {
            $solutions[] = 'Perplanuokite kaimyninius augalus arba pasirinkite kitÄ… augalÅ³ kombinacijÄ…, kad neliktÅ³ to paties tipo konflikto.';
        }

        if ($solutions === []) {
            $solutions[] = 'Å iuo metu Åiam augalui nerasta validi zona. Reikia papildomos zonos arba augalÅ³ perskirstymo prieÅ tvirtinant schemÄ….';
        }

        return array_values(array_unique($solutions));
    }

    /**
     * @param  array<int, string>  $positiveReasons
     * @param  array<int, string>  $softWarnings
     * @param  array<string, mixed>  $plantProfile
     */
    private function calculateScore(
        array $positiveReasons,
        array $softWarnings,
        float $remainingCapacity,
        PlantZone $targetZone,
        Plant $plant,
        array $plantProfile
    ): int {
        $score = count($positiveReasons) * 2;
        $score -= count($softWarnings);

        if ($remainingCapacity >= 0) {
            $score += min(3, (int) floor($remainingCapacity));
        }

        if ((int) ($plant->plant_zone_id ?? $plant->fk_plant_zone_id) !== (int) $targetZone->id) {
            $score += 1;
        }

        if (($plantProfile['nutrient_role'] ?? null) === 'heavy_feeder') {
            $score += 1;
        }

        return $score;
    }

    /**
     * @param  array<int, string>  $hardBlockingReasons
     * @param  array<int, string>  $softWarnings
     * @return array<int, string>
     */
    private function moveBroadTypeConflictsToWarnings(array $hardBlockingReasons, array &$softWarnings): array
    {
        $keptReasons = [];

        foreach ($hardBlockingReasons as $reason) {
            if (Str::contains($reason, ['to paties tipo', 'same-type conflict'])) {
                $softWarnings[] = 'Broad plant type matches are informational only; family or rotation group data is used for blocking rotation conflicts.';

                continue;
            }

            $keptReasons[] = $reason;
        }

        return array_values(array_unique($keptReasons));
    }

    /**
     * @param  Collection<int, Plant>  $plants
     */
    private function plantNames(Collection $plants): string
    {
        return $plants
            ->map(fn (Plant $plant) => $plant->name)
            ->filter()
            ->values()
            ->join(', ');
    }

    private function normalizedPlantName(Plant $plant): string
    {
        return Str::lower(trim((string) $plant->name));
    }

    /**
     * @return array{message: string, conflicting_previous_plant: array<string, mixed>|null, previous_season: string|null, previous_year: int|null}
     */
    private function rotationConflictPayload(string $message, RotationHistory $history): array
    {
        $referenceDate = $history->to_date ?? $history->from_date;

        return [
            'message' => $message,
            'conflicting_previous_plant' => $history->plant ? $this->plantSummary($history->plant) : null,
            'previous_season' => $referenceDate?->format('Y'),
            'previous_year' => $referenceDate ? (int) $referenceDate->format('Y') : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function zoneSummary(?PlantZone $zone): ?array
    {
        if (! $zone) {
            return null;
        }

        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'soil_type' => $zone->soil_type?->value ?? $zone->soil_type,
            'zone_size' => $zone->zone_size === null ? null : (float) $zone->zone_size,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plantSummary(Plant $plant): array
    {
        return [
            'id' => $plant->id,
            'name' => $plant->name,
            'type' => $plant->type?->value ?? $plant->type,
            'plant_size' => $plant->plant_size === null ? null : (float) $plant->plant_size,
            'rest_time_days' => $plant->rest_time_days,
            'reusable' => (bool) $plant->reusable,
        ];
    }

    private function resolvePlantCare(Plant $plant): ?PlantCare
    {
        if ($plant->relationLoaded('catalogPlant') && $plant->catalogPlant?->relationLoaded('plantCare')) {
            return $plant->catalogPlant->plantCare;
        }

        return $plant->effectivePlantCare();
    }

    private function normalizePlanningDate(?string $planningDate = null): CarbonImmutable
    {
        return CarbonImmutable::parse($planningDate ?: now()->toDateString());
    }
}
