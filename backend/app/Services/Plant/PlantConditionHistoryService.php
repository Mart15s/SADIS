<?php

namespace App\Services\Plant;

use App\Enums\ConditionType;
use App\Models\Plant;
use App\Models\PlantConditionHistory;
use App\Models\Plot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class PlantConditionHistoryService
{
    /**
     * @return Collection<int, PlantConditionHistory>
     */
    public function listForPlant(Plant $plant): Collection
    {
        return $plant->conditionHistory()
            ->latest('measured_at')
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, PlantConditionHistory>
     */
    public function listForPlot(Plot $plot): Collection
    {
        return PlantConditionHistory::query()
            ->with([
                'plant:id,name,plant_zone_id,fk_plant_zone_id,fk_plot_id,type,condition',
                'plant.plantZone:id,name,fk_plot_id',
            ])
            ->whereIn('plant_id', $plot->plants()->select('id'))
            ->orderBy('measured_at')
            ->orderBy('id')
            ->get();
    }

    public function record(
        Plant $plant,
        string|ConditionType $condition,
        Carbon|string $measuredAt,
        ?string $notes = null,
        ?string $photoUrl = null,
        ?bool $disease = null,
    ): PlantConditionHistory {
        $resolvedCondition = $condition instanceof ConditionType
            ? $condition->value
            : (string) $condition;

        $history = PlantConditionHistory::query()->create([
            'measured_at' => $measuredAt instanceof Carbon ? $measuredAt->toDateTimeString() : $measuredAt,
            'notes' => $notes,
            'photo_url' => $photoUrl,
            'condition' => $resolvedCondition,
            'condition_type' => $resolvedCondition,
            'plant_id' => $plant->id,
            'fk_plant_id' => $plant->id,
        ]);

        $plant->update([
            'condition' => $resolvedCondition,
            'disease' => $disease ?? ($resolvedCondition === ConditionType::Diseased->value),
        ]);

        return $history->fresh();
    }
}
