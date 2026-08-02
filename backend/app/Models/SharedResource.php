<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class SharedResource extends YavaModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['requires_approval' => 'boolean'];
    }
}
