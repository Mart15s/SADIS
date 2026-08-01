<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmCommunityLink extends YavaModel
{
    protected function casts(): array { return ['analytics_scopes' => 'array', 'farm_access_permissions' => 'array', 'requested_at' => 'datetime', 'approved_at' => 'datetime', 'revoked_at' => 'datetime']; }
    public function farm(): BelongsTo { return $this->belongsTo(Farm::class); }
    public function community(): BelongsTo { return $this->belongsTo(Community::class); }
    public function events(): HasMany { return $this->hasMany(FarmCommunityLinkEvent::class); }
}
