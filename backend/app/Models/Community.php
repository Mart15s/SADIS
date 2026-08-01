<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Community extends YavaModel
{
    use SoftDeletes;

    public function memberships(): HasMany { return $this->hasMany(CommunityMembership::class); }
    public function members(): BelongsToMany { return $this->belongsToMany(User::class, 'community_memberships')->withPivot(['role', 'status'])->withTimestamps(); }
    public function farms(): BelongsToMany { return $this->belongsToMany(Farm::class, 'farm_community_links')->withPivot(['status', 'analytics_scopes', 'farm_access_permissions'])->withTimestamps(); }
}
