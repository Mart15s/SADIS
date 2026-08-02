<?php

namespace App\Models;

class FieldZone extends YavaModel
{
    protected function casts(): array
    {
        return ['boundary' => 'array', 'area_square_metres' => 'decimal:2', 'is_whole_field' => 'boolean'];
    }
}
