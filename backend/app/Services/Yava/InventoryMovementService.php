<?php

namespace App\Services\Yava;

use App\Models\InventoryMovement;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryMovementService
{
    public function record(User $actor, StockItem $item, array $data): InventoryMovement
    {
        return DB::transaction(function () use ($actor, $item, $data): InventoryMovement {
            $locked = StockItem::query()->lockForUpdate()->findOrFail($item->id);
            $quantity = (float) $data['quantity'];
            $delta = match ($data['type']) {
                'receipt', 'adjustment_in', 'return' => $quantity,
                'issue', 'consumption', 'adjustment_out' => -$quantity,
                default => throw ValidationException::withMessages(['type' => ['Unsupported inventory movement type.']]),
            };
            $balance = (float) $locked->quantity + $delta;
            if ($balance < 0) {
                throw ValidationException::withMessages(['quantity' => ['The movement would make inventory negative.']]);
            }
            $locked->update(['quantity' => $balance]);

            return InventoryMovement::query()->create([
                'stock_item_id' => $locked->id, 'actor_user_id' => $actor->id,
                'work_task_id' => $data['work_task_id'] ?? null, 'type' => $data['type'],
                'quantity' => $quantity, 'balance_after' => $balance,
                'notes' => $data['notes'] ?? null, 'occurred_at' => $data['occurred_at'] ?? now(),
            ]);
        }, 3);
    }
}
