<?php

namespace App\Http\Resources\Harvest;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HarvestRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plant = $this->relationLoaded('plant') ? $this->plant : null;
        $zone = $plant?->relationLoaded('plantZone') ? $plant->plantZone : null;
        $task = $this->relationLoaded('task') ? $this->task : null;

        return [
            'id' => $this->id,
            'plot_id' => $this->plot_id,
            'plant_id' => $this->plant_id,
            'plant_name' => $plant?->name,
            'zone_id' => $plant?->plant_zone_id ?? $plant?->fk_plant_zone_id,
            'zone_name' => $zone?->name,
            'task_id' => $this->task_id,
            'task_name' => $task?->name,
            'quantity' => $this->quantity === null ? null : (float) $this->quantity,
            'harvested_on' => $this->harvested_on?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
