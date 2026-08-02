<?php

namespace App\Models;

class CropVariety extends YavaModel
{
    protected function casts(): array
    {
        return ['is_global' => 'boolean'];
    }
}
