<?php

namespace App\Models;

class InventoryMovement extends YavaModel
{
    protected function casts(): array { return ['quantity' => 'decimal:3', 'balance_after' => 'decimal:3', 'occurred_at' => 'datetime']; }
}
