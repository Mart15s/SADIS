<?php

namespace App\Models;

class LegacyRecordMapping extends YavaModel
{
    protected function casts(): array
    {
        return ['evidence' => 'array', 'confidence' => 'decimal:4'];
    }
}
