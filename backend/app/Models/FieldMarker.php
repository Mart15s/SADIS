<?php

namespace App\Models;

class FieldMarker extends YavaModel
{
    protected function casts(): array
    {
        return ['position' => 'array', 'metadata' => 'array'];
    }
}
