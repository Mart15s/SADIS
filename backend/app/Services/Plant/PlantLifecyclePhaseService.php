<?php

namespace App\Services\Plant;

use App\Enums\ConditionType;
use App\Models\Plant;
use App\Models\PlantCare;
use Carbon\Carbon;

class PlantLifecyclePhaseService
{
    /**
     * Day-boundary rule:
     * - planting day (elapsed day 0) stays in the planted phase
     * - each configured duration starts on the next elapsed day and occupies that many whole elapsed days
     * - once the last configured phase is reached, it remains the expected simulated phase for later dates
     *
     * @return array<string, mixed>
     */
    public function resolveSimulatedPhase(Plant $plant, PlantCare $care, Carbon $forDate): array
    {
        $elapsedDays = $plant->plant_date->copy()->startOfDay()->diffInDays($forDate->copy()->startOfDay(), false);
        $actualCondition = $plant->condition?->value ?? (string) $plant->condition;

        if ($elapsedDays < 0) {
            return [
                'elapsed_days_from_planted' => $elapsedDays,
                'simulated_phase' => null,
                'phase_label' => null,
                'current_phase_start_day' => null,
                'next_phase' => ConditionType::Planted->value,
                'is_transition_day' => false,
                'transition' => null,
                'actual_condition' => $actualCondition,
                'is_exceptional_actual_condition' => $this->isExceptionalManualCondition($actualCondition),
            ];
        }

        $timeline = $this->buildTimeline($care);
        $current = $this->resolvePhaseFromTimeline($timeline, $elapsedDays);
        $previous = $elapsedDays > 0
            ? $this->resolvePhaseFromTimeline($timeline, $elapsedDays - 1)
            : null;

        if ($current === null) {
            return [
                'elapsed_days_from_planted' => $elapsedDays,
                'simulated_phase' => null,
                'phase_label' => null,
                'current_phase_start_day' => null,
                'next_phase' => null,
                'is_transition_day' => false,
                'transition' => $previous ? [
                    'from' => $previous['phase'],
                    'to' => null,
                    'is_transition_day' => true,
                ] : null,
                'actual_condition' => $actualCondition,
                'is_exceptional_actual_condition' => $this->isExceptionalManualCondition($actualCondition),
            ];
        }

        $transition = $previous && $previous['phase'] !== $current['phase']
            ? [
                'from' => $previous['phase'],
                'to' => $current['phase'],
                'is_transition_day' => true,
            ]
            : null;

        return [
            'elapsed_days_from_planted' => $elapsedDays,
            'simulated_phase' => $current['phase'],
            'phase_label' => $this->phaseLabel($current['phase']),
            'current_phase_start_day' => $current['start_day'],
            'next_phase' => $current['next_phase'],
            'is_transition_day' => $transition !== null,
            'transition' => $transition,
            'actual_condition' => $actualCondition,
            'is_exceptional_actual_condition' => $this->isExceptionalManualCondition($actualCondition),
        ];
    }

    /**
     * @return array<int, array{phase:string,start_day:int,end_day:int|null,next_phase:string|null}>
     */
    public function buildTimeline(PlantCare $care): array
    {
        $timeline = [[
            'phase' => ConditionType::Planted->value,
            'start_day' => 0,
            'end_day' => 0,
            'next_phase' => null,
        ]];
        $cursor = 1;
        $orderedPhases = [
            ConditionType::Germinating->value => max(0, (int) ($care->germinating_duration_days ?? 0)),
            ConditionType::Growing->value => max(0, (int) ($care->growing_duration_days ?? 0)),
            ConditionType::Flowering->value => max(0, (int) ($care->flowering_duration_days ?? 0)),
            ConditionType::Mature->value => max(0, (int) ($care->mature_duration_days ?? 0)),
            ConditionType::Regenerating->value => max(0, (int) ($care->regenerating_duration_days ?? 0)),
        ];

        foreach ($orderedPhases as $phase => $duration) {
            if ($duration <= 0) {
                continue;
            }

            $timeline[] = [
                'phase' => $phase,
                'start_day' => $cursor,
                'end_day' => $cursor + $duration - 1,
                'next_phase' => null,
            ];
            $cursor += $duration;
        }

        if (count($timeline) === 1) {
            $timeline[] = [
                'phase' => ConditionType::Mature->value,
                'start_day' => 1,
                'end_day' => null,
                'next_phase' => null,
            ];
        }

        foreach ($timeline as $index => $phase) {
            $timeline[$index]['next_phase'] = $timeline[$index + 1]['phase'] ?? null;
        }

        $lastIndex = array_key_last($timeline);

        if (($timeline[$lastIndex]['phase'] ?? null) === ConditionType::Regenerating->value) {
            $timeline[$lastIndex]['end_day'] = null;
        }

        return $timeline;
    }

    /**
     * @param  array<int, array{phase:string,start_day:int,end_day:int|null,next_phase:string|null}>  $timeline
     * @return array{phase:string,start_day:int,end_day:int|null,next_phase:string|null}|null
     */
    public function resolvePhaseFromTimeline(array $timeline, int $elapsedDays): ?array
    {
        foreach ($timeline as $phase) {
            $endDay = $phase['end_day'];

            if ($elapsedDays < $phase['start_day']) {
                continue;
            }

            if ($endDay === null || $elapsedDays <= $endDay) {
                return $phase;
            }
        }

        return null;
    }

    public function isExceptionalManualCondition(?string $condition): bool
    {
        return in_array($condition, [
            ConditionType::Diseased->value,
            ConditionType::Dried->value,
        ], true);
    }

    public function phaseLabel(?string $phase): ?string
    {
        return match ($phase) {
            ConditionType::Planted->value => 'Planted',
            ConditionType::Germinating->value => 'Germinating',
            ConditionType::Growing->value => 'Growing',
            ConditionType::Flowering->value => 'Flowering',
            ConditionType::Mature->value => 'Mature',
            ConditionType::Regenerating->value => 'Regenerating',
            default => null,
        };
    }
}
