<?php

namespace App\Http\Resources\Calendar;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plant = $this->relationLoaded('plant') ? $this->plant : null;
        $inventoryContext = $this->live_inventory_context ?? $this->inventory_context;
        $zone = $this->relationLoaded('plantZone') && $this->plantZone
            ? $this->plantZone
            : ($plant?->relationLoaded('plantZone') && $plant->plantZone
                ? $plant->plantZone
                : ($this->relationLoaded('usedOn') ? $this->usedOn->first()?->plantZone : null));

        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'name' => $this->name,
            'type' => $this->task_type ?? $this->type,
            'task_type' => $this->task_type ?? $this->type,
            'priority' => $this->priority?->value ?? $this->priority,
            'reason' => $this->reason,
            'status' => $this->state?->value ?? $this->status,
            'state' => $this->state?->value ?? $this->status,
            'comment' => $this->comment,
            'item' => $this->item,
            'item_quantity' => $this->item_quantity === null ? null : (float) $this->item_quantity,
            'required_resources' => TaskResourceRequirementResource::collection($this->whenLoaded('requiredResources')),
            'weather_context' => $this->weather_context,
            'inventory_context' => $inventoryContext,
            'can_complete' => (bool) ($this->can_complete_now ?? ($inventoryContext['is_actionable'] ?? true)),
            'simulated_state' => $this->simulated_state,
            'plant_id' => $this->plant_id ?? $this->fk_plant_id,
            'zone_id' => $zone?->id,
            'plant_name' => $plant?->name,
            'zone_name' => $zone?->name,
        ];
    }
}
