<?php

namespace App\Services;

use App\Enums\ConditionType;
use App\Models\Plant;
use App\Models\PlantCare;
use Carbon\Carbon;

class PlantStateService
{
    /**
     * @return array<string, mixed>
     */
    public function simulatePlantState(Plant $plant, PlantCare $care, Carbon $simulatedDate): array
    {
        $daysSincePlanting = $plant->plant_date->copy()->startOfDay()->diffInDays($simulatedDate->copy()->startOfDay(), false);

        if ($daysSincePlanting < 0) {
            return [
                'days_since_planting' => $daysSincePlanting,
                'phase' => 'not_planted',
                'condition' => $plant->condition?->value ?? ConditionType::Planted->value,
                'state_label' => 'Not planted yet',
                'is_active' => false,
                'is_establishing' => false,
                'is_harvest_ready' => false,
                'is_diseased' => (bool) $plant->disease,
                'is_inactive' => true,
                'is_phase_start' => false,
                'lifecycle_day' => null,
            ];
        }

        $durations = $this->resolveDurations($care);
        $daysForLifecycle = $daysSincePlanting;
        $reusable = (bool) ($plant->reusable || $care->reusable);

        if ($reusable) {
            $cycleLength = max(1, array_sum($durations));
            $daysForLifecycle = $daysSincePlanting % $cycleLength;
        }

        $growthStart = $durations['early'];
        $matureStart = $growthStart + $durations['growth'];
        $harvestStart = $matureStart + $durations['mature'];
        $harvestEnd = $harvestStart + $durations['harvest_ready'];

        $phase = 'early';
        $condition = $daysForLifecycle === 0 ? ConditionType::Planted->value : ConditionType::Germinating->value;

        if ($plant->disease) {
            $condition = ConditionType::Diseased->value;
        } elseif ($daysForLifecycle < $growthStart) {
            $condition = $daysForLifecycle === 0 ? ConditionType::Planted->value : ConditionType::Germinating->value;
        } elseif ($daysForLifecycle < $matureStart) {
            $phase = 'growth';
            $condition = $daysForLifecycle < ($growthStart + max(1, $durations['growing']))
                ? ConditionType::Growing->value
                : ConditionType::Flowering->value;
        } elseif ($daysForLifecycle < $harvestStart) {
            $phase = 'mature';
            $condition = ConditionType::Mature->value;
        } elseif ($daysForLifecycle < $harvestEnd) {
            $phase = 'harvest_ready';
            $condition = ConditionType::Mature->value;
        } elseif ($reusable) {
            $phase = 'growth';
            $condition = ConditionType::Regenerating->value;
        } else {
            $phase = 'inactive';
            $condition = ConditionType::Dried->value;
        }

        $isActive = $phase !== 'inactive';

        return [
            'days_since_planting' => $daysSincePlanting,
            'lifecycle_day' => $daysForLifecycle,
            'phase' => $phase,
            'condition' => $condition,
            'state_label' => $this->stateLabel($phase, $condition),
            'is_active' => $isActive,
            'is_establishing' => $phase === 'early',
            'is_harvest_ready' => $phase === 'harvest_ready',
            'is_diseased' => $condition === ConditionType::Diseased->value,
            'is_inactive' => ! $isActive,
            'is_phase_start' => $this->isPhaseStart(
                $phase,
                $daysForLifecycle,
                $growthStart,
                $matureStart,
                $harvestStart
            ),
            'growth_start_day' => $growthStart,
            'mature_start_day' => $matureStart,
            'harvest_start_day' => $harvestStart,
            'harvest_window_days' => $durations['harvest_ready'],
            'durations' => $durations,
        ];
    }

    /**
     * @return array{early:int,growth:int,growing:int,mature:int,harvest_ready:int,regenerating:int}
     */
    private function resolveDurations(PlantCare $care): array
    {
        $early = max(1, (int) ($care->germinating_duration_days ?? 0) + 1);
        $growing = max(1, (int) ($care->growing_duration_days ?? 0));
        $flowering = max(0, (int) ($care->flowering_duration_days ?? 0));
        $growth = max(1, $growing + $flowering);
        $mature = max(1, (int) ($care->mature_duration_days ?? 0));
        $harvestReady = max(1, (int) ($care->mature_duration_end_days ?? $care->mature_end_duration_days ?? 0));
        $regenerating = max(0, (int) ($care->regenerating_duration_days ?? 0));

        return [
            'early' => $early,
            'growth' => $growth,
            'growing' => $growing,
            'mature' => $mature,
            'harvest_ready' => $harvestReady,
            'regenerating' => $regenerating,
        ];
    }

    private function isPhaseStart(
        string $phase,
        int $daysForLifecycle,
        int $growthStart,
        int $matureStart,
        int $harvestStart,
    ): bool {
        return match ($phase) {
            'early' => $daysForLifecycle === 0,
            'growth' => $daysForLifecycle === $growthStart,
            'mature' => $daysForLifecycle === $matureStart,
            'harvest_ready' => $daysForLifecycle === $harvestStart,
            default => false,
        };
    }

    private function stateLabel(string $phase, string $condition): string
    {
        return match ($phase) {
            'early' => 'Early establishment',
            'growth' => $condition === ConditionType::Regenerating->value ? 'Regenerating growth' : 'Growth phase',
            'mature' => 'Mature maintenance',
            'harvest_ready' => 'Harvest ready',
            'inactive' => 'Lifecycle finished',
            default => 'Planned state',
        };
    }
}
