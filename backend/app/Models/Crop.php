<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Crop extends YavaModel
{
    protected function casts(): array
    {
        return ['is_global' => 'boolean'];
    }

    public function varieties(): HasMany
    {
        return $this->hasMany(CropVariety::class);
    }
}
