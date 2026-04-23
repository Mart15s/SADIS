<?php

namespace App\Http\Resources\Calendar;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResourceRequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $itemType = $this->inventory_item_type?->value ?? $this->inventory_item_type;
        $unit = $this->unit?->value ?? $this->unit;
        $resourceMode = $this->resource_mode ?? ($this->is_consumed ? 'consumable' : 'reusable');

        return [
            'id' => $this->id,
            'name' => $this->resource_name,
            'type' => $itemType,
            'unit' => $unit,
            'resource_mode' => $resourceMode,
            'resource_type_label' => $resourceMode === 'consumable' ? 'Consumable' : 'Reusable',
            'required_quantity' => $this->required_quantity === null ? null : (float) $this->required_quantity,
            'available_quantity' => $this->available_quantity === null ? null : (float) $this->available_quantity,
            'shortage_quantity' => $this->live_shortage_quantity === null
                ? ($this->shortage_quantity === null ? null : (float) $this->shortage_quantity)
                : (float) $this->live_shortage_quantity,
            'daily_required_quantity' => $this->daily_required_quantity === null ? null : (float) $this->daily_required_quantity,
            'daily_available_quantity' => $this->daily_available_quantity === null ? null : (float) $this->daily_available_quantity,
            'daily_shortage_quantity' => $this->daily_shortage_quantity === null ? null : (float) $this->daily_shortage_quantity,
            'is_consumed' => (bool) $this->is_consumed,
            'consumption_mode' => $resourceMode,
            'is_sufficient' => $this->is_sufficient === null
                ? (float) ($this->shortage_quantity ?? 0) <= 0
                : (bool) $this->is_sufficient,
            'is_shortage' => $this->live_shortage_quantity === null
                ? (float) ($this->shortage_quantity ?? 0) > 0
                : (float) $this->live_shortage_quantity > 0,
            'is_daily_shortage' => (float) ($this->daily_shortage_quantity ?? $this->shortage_quantity ?? 0) > 0,
            'buy_task_ids' => $this->buy_task_ids ?? [],
        ];
    }
}
