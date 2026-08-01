<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Farm extends YavaModel
{
    use SoftDeletes;

    protected function casts(): array { return ['area_square_metres' => 'decimal:2']; }
    public function memberships(): HasMany { return $this->hasMany(FarmMembership::class); }
    public function members(): BelongsToMany { return $this->belongsToMany(User::class, 'farm_memberships')->withPivot(['role', 'status'])->withTimestamps(); }
    public function communities(): BelongsToMany { return $this->belongsToMany(Community::class, 'farm_community_links')->withPivot(['status', 'analytics_scopes', 'farm_access_permissions'])->withTimestamps(); }
    public function fields(): HasMany { return $this->hasMany(Field::class); }
}
