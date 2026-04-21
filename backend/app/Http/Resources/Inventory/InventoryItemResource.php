<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->inventory_item_type?->value ?? $this->type?->value ?? $this->type;
        $quantity = $this->quantity === null ? null : (float) $this->quantity;
        $minimumQuantity = $this->minimum_quantity === null ? 0.0 : (float) $this->minimum_quantity;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $quantity,
            'minimum_quantity' => $minimumQuantity,
            'type' => $type,
            'inventory_item_type' => $type,
            'unit' => $this->unit?->value ?? $this->unit,
            'consumption_mode' => $type === 'tool' ? 'reusable' : 'consumable',
            'is_below_minimum' => $quantity !== null && $quantity <= $minimumQuantity,
        ];
    }
}
