<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockItem extends YavaModel
{
    protected function casts(): array { return ['quantity' => 'decimal:3', 'reorder_level' => 'decimal:3']; }
    public function movements(): HasMany { return $this->hasMany(InventoryMovement::class); }
    public function farm(): BelongsTo { return $this->belongsTo(Farm::class); }
    public function community(): BelongsTo { return $this->belongsTo(Community::class); }
}
