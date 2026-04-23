<?php

namespace App\Http\Resources\Plant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlantConditionHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $condition = $this->condition_type?->value
            ?? $this->condition?->value
            ?? $this->condition_type
            ?? $this->condition;

        return [
            'id' => $this->id,
            'plant_id' => $this->plant_id ?? $this->fk_plant_id,
            'measured_at' => $this->measured_at?->toIso8601String(),
            'condition' => $condition,
            'condition_type' => $condition,
            'notes' => $this->notes,
            'photo_url' => $this->photo_url,
        ];
    }
}
