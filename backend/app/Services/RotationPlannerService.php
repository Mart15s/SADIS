<?php

namespace App\Services;

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
        $selectedAssignments = [];
        $plantOrder = $this->orderPlantsForPlanning($plot, $planningDate);
        $planPlants = [];

        foreach ($plantOrder as $plant) {
            $candidates = $plot->plantZones
                ->map(fn (PlantZone $zone) => $this->evaluateZoneCandidate(
                    $plot,
                    $plant,
                    $zone,
                    $planningDate,
                    $selectedAssignments
                ))
                ->sortByDesc('score')
                ->values();

            $alternatives = $candidates
                ->filter(fn (array $candidate) => $candidate['verdict'] === 'valid')
                ->values();
            $selectedTarget = $alternatives->first();

            if ($selectedTarget) {
                $selectedAssignments[$plant->id] = $selectedTarget['zone_id'];
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
                'generated_at' => now()->toIso8601String(),
            ],
            'plants' => $planPlants,
        ];
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
        $passedReasons = [];
        $blockingReasons = [];
        $currentZoneId = (int) ($plant->plant_zone_id ?? $plant->fk_plant_zone_id);
        $currentZone = $plant->plantZone;
        $tentativePlants = $this->tentativePlantsForZone($plot, $targetZone, $plant, $selectedAssignments);
        $remainingCapacity = $this->remainingZoneCapacity($targetZone, $tentativePlants, $plant);

        if ($currentZoneId !== 0 && $currentZoneId === (int) $targetZone->id && ! (bool) $plant->reusable) {
            $blockingReasons[] = 'Tikslinė zona sutampa su dabartine augalo zona, todėl tai nėra tinkama rotacija.';
        } else {
            $passedReasons[] = $currentZoneId === (int) $targetZone->id
                ? 'Augalas gali likti dabartinėje zonoje tik todėl, kad pažymėtas kaip daugiametis ar pakartotinai naudojamas.'
                : 'Tikslinė zona skiriasi nuo dabartinės zonos, todėl atitinka bazinį rotacijos perkėlimo principą.';
        }

        if ($remainingCapacity < 0) {
            $blockingReasons[] = 'Tikslinėje zonoje nepakanka vietos šiam augalui.';
        } else {
            $passedReasons[] = 'Tikslinėje zonoje pakanka vietos šiam augalui.';
        }

        $sameNameConflict = $tentativePlants->contains(function (Plant $zonePlant) use ($plant): bool {
            return Str::lower((string) $zonePlant->name) === Str::lower((string) $plant->name);
        });

        if ($sameNameConflict) {
            $blockingReasons[] = 'Tikslinėje zonoje jau yra toks pats augalas.';
        } else {
            $passedReasons[] = 'Tikslinėje zonoje nėra tokio paties augalo konflikto.';
        }

        $sameTypeConflict = $tentativePlants->contains(function (Plant $zonePlant) use ($plant): bool {
            return ($zonePlant->type?->value ?? $zonePlant->type) === ($plant->type?->value ?? $plant->type);
        });

        if ($sameTypeConflict) {
            $blockingReasons[] = 'Tikslinėje zonoje jau yra to paties tipo augalų konfliktas.';
        } else {
            $passedReasons[] = 'Tikslinėje zonoje nėra to paties tipo augalų konflikto.';
        }

        $rotationConflict = $this->recentRotationConflict($targetZone, $plant, $planningDate);
        if ($rotationConflict !== null) {
            $blockingReasons[] = $rotationConflict;
        } else {
            $passedReasons[] = 'Tikslinėje zonoje nėra per neseniai sodinto to paties augalo ar tipo rotacijos konflikto.';
        }

        $restConflict = $this->restIntervalConflict($targetZone, $plant, $planningDate);
        if ($restConflict !== null) {
            $blockingReasons[] = $restConflict;
        } else {
            $passedReasons[] = 'Tikslinė zona atitinka minimalų poilsio intervalą.';
        }

        $soilCompatibility = $this->soilCompatibilityForZone($plant, $targetZone);
        if ($soilCompatibility === false) {
            $blockingReasons[] = 'Tikslinės zonos dirvožemis neatitinka augalo priežiūros profilio signalų.';
        } elseif ($soilCompatibility === true) {
            $passedReasons[] = 'Tikslinės zonos dirvožemis atitinka augalo priežiūros profilio signalus.';
        }

        $verdict = $blockingReasons === [] ? 'valid' : 'invalid';
        $score = $this->calculateScore($passedReasons, $blockingReasons, $remainingCapacity, $targetZone, $plant);

        return [
            'zone_id' => $targetZone->id,
            'zone_name' => $targetZone->name,
            'verdict' => $verdict,
            'score' => $verdict === 'valid' ? $score : min(0, $score),
            'remaining_capacity' => round($remainingCapacity, 2),
            'is_current_zone' => $currentZoneId !== 0 && $currentZoneId === (int) $targetZone->id,
            'current_zone_name' => $currentZone?->name,
            'passed_reasons' => $passedReasons,
            'blocking_reasons' => $blockingReasons,
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
            ->filter(fn (array $candidate) => $candidate['verdict'] === 'valid')
            ->sortByDesc('score')
            ->values();
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
                    ->filter(fn (PlantZone $zone) => $this->evaluateZoneCandidate($plot, $plant, $zone, $planningDate, [])['verdict'] === 'valid')
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

    private function recentRotationConflict(PlantZone $targetZone, Plant $plant, CarbonImmutable $planningDate): ?string
    {
        $restWindow = max(1, (int) ($plant->rest_time_days ?? 0));
        $cutoffDate = $planningDate->subDays($restWindow);
        $plantType = $plant->type?->value ?? $plant->type;

        $recentPlantRotation = $targetZone->rotationHistory
            ->filter(function (RotationHistory $history) use ($plant, $cutoffDate): bool {
                $referenceDate = $history->to_date ?? $history->from_date;

                return (int) $history->fk_plant_id === (int) $plant->id
                    && $referenceDate !== null
                    && $referenceDate->greaterThanOrEqualTo($cutoffDate);
            })
            ->sortByDesc(fn (RotationHistory $history) => ($history->to_date ?? $history->from_date)?->timestamp ?? 0)
            ->first();

        if ($recentPlantRotation) {
            return 'Tikslinėje zonoje tas pats augalas buvo sodintas per neseniai pagal rotacijos istoriją.';
        }

        $recentTypeRotation = $targetZone->rotationHistory
            ->filter(function (RotationHistory $history) use ($plantType, $cutoffDate): bool {
                $referenceDate = $history->to_date ?? $history->from_date;
                $historyType = $history->plant?->type?->value ?? $history->plant?->type;

                return $plantType !== null
                    && $historyType === $plantType
                    && $referenceDate !== null
                    && $referenceDate->greaterThanOrEqualTo($cutoffDate);
            })
            ->sortByDesc(fn (RotationHistory $history) => ($history->to_date ?? $history->from_date)?->timestamp ?? 0)
            ->first();

        if ($recentTypeRotation) {
            return 'Tikslinėje zonoje to paties tipo augalai buvo sodinti per neseniai pagal pasėlių kaitos taisykles.';
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
            return 'Tikslinė zona dar nėra išlaukusi minimalaus poilsio intervalo.';
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
            $solutions[] = 'Padidinkite arba atlaisvinkite zoną "'.$bestBlockedZone['zone_name'].'" prieš planuodami rotaciją.';
        }

        if ($candidates->contains(fn (array $candidate) => collect($candidate['blocking_reasons'] ?? [])->contains(
            fn (string $reason) => Str::contains($reason, 'poilsio interval')
        ))) {
            $solutions[] = 'Atidėkite rotaciją vėlesnei datai, kad zonos spėtų išlaukti poilsio intervalą.';
        }

        if ($candidates->contains(fn (array $candidate) => collect($candidate['blocking_reasons'] ?? [])->contains(
            fn (string $reason) => Str::contains($reason, 'to paties tipo')
        ))) {
            $solutions[] = 'Perplanuokite kaimyninius augalus arba pasirinkite kitą augalų kombinaciją, kad neliktų to paties tipo konflikto.';
        }

        if ($solutions === []) {
            $solutions[] = 'Šiuo metu šiam augalui nerasta validi zona. Reikia papildomos zonos arba augalų perskirstymo prieš tvirtinant schemą.';
        }

        return array_values(array_unique($solutions));
    }

    /**
     * @param  array<int, string>  $passedReasons
     * @param  array<int, string>  $blockingReasons
     */
    private function calculateScore(
        array $passedReasons,
        array $blockingReasons,
        float $remainingCapacity,
        PlantZone $targetZone,
        Plant $plant
    ): int {
        $score = count($passedReasons) * 2;
        $score -= count($blockingReasons) * 3;

        if ($remainingCapacity >= 0) {
            $score += min(3, (int) floor($remainingCapacity));
        }

        if ((int) ($plant->plant_zone_id ?? $plant->fk_plant_zone_id) !== (int) $targetZone->id) {
            $score += 1;
        }

        return $score;
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
