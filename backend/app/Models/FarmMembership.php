<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmMembership extends YavaModel
{
    protected function casts(): array { return ['joined_at' => 'datetime', 'revoked_at' => 'datetime']; }
    public function farm(): BelongsTo { return $this->belongsTo(Farm::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function permissions(): HasMany { return $this->hasMany(FarmMemberPermission::class); }
}
