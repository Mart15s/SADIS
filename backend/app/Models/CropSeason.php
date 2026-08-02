<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CropSeason extends YavaModel
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'expected_ends_on' => 'date', 'ended_on' => 'date', 'planted_area_square_metres' => 'decimal:2'];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class);
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(CropConditionRecord::class);
    }

    public function harvests(): HasMany
    {
        return $this->hasMany(CropHarvest::class);
    }
}
