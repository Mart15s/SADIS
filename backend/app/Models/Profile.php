<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Profile extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'name',
        'surname',
        'last_login',
    ];

    protected function casts(): array
    {
        return [
            'last_login' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function gardenOwner(): HasOne
    {
        return $this->hasOne(GardenOwner::class, 'fk_profile_id');
    }

    public function grantedAccessRights(): HasMany
    {
        return $this->hasMany(AccessRight::class, 'fk_grantor_profile_id');
    }

    public function receivedAccessRights(): HasMany
    {
        return $this->hasMany(AccessRight::class, 'fk_recipient_profile_id');
    }

    public function communityPosts(): HasMany
    {
        return $this->hasMany(CommunityPost::class, 'fk_profile_id');
    }

    public function plotLinks(): HasMany
    {
        return $this->hasMany(HasPlot::class, 'fk_profile_id');
    }

    public function inventoryLinks(): HasMany
    {
        return $this->hasMany(HasInventory::class, 'fk_profile_id');
    }
}
