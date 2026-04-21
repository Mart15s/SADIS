<?php

namespace App\Services;

use App\Models\Plant;
use App\Models\PlantConditionHistory;
use App\Models\Plot;
use Illuminate\Database\Eloquent\Collection;

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
}
