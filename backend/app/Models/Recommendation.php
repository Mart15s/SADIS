<?php

namespace App\Models;

class Recommendation extends YavaModel
{
    protected function casts(): array { return ['weather_context' => 'array', 'valid_until' => 'datetime']; }
}
