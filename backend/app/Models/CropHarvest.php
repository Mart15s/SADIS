<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropHarvest extends YavaModel
{
    protected function casts(): array { return ['quantity' => 'decimal:3', 'harvested_on' => 'date']; }
    public function cropSeason(): BelongsTo { return $this->belongsTo(CropSeason::class); }
}
