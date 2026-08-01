<?php

namespace App\Models;

class CropConditionRecord extends YavaModel
{
    protected function casts(): array { return ['observations' => 'array', 'observed_at' => 'datetime']; }
}
