<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceReservation extends YavaModel
{
    protected function casts(): array { return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'decided_at' => 'datetime']; }
    public function resource(): BelongsTo { return $this->belongsTo(SharedResource::class, 'shared_resource_id'); }
}
