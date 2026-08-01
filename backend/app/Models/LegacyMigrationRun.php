<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LegacyMigrationRun extends YavaModel
{
    use HasUuids;
    public $incrementing = false;
    protected $keyType = 'string';
    protected function casts(): array { return ['dry_run' => 'boolean', 'counts' => 'array', 'options' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime']; }
}
