<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Field extends YavaModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['boundary' => 'array', 'area_square_metres' => 'decimal:2'];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(FieldZone::class);
    }

    public function markers(): HasMany
    {
        return $this->hasMany(FieldMarker::class);
    }

    public function cropSeasons(): HasMany
    {
        return $this->hasMany(CropSeason::class);
    }
}
