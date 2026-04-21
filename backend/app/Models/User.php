<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = [
        'role' => UserRole::Owner->value,
    ];

    protected $fillable = [
        'email',
        'password',
        'reset_code',
        'role',
    ];

    protected $hidden = [
        'password',
        'reset_code',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function gardenOwner(): HasOne
    {
        return $this->hasOne(GardenOwner::class, 'user_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    public function grantedAccessRights(): HasMany
    {
        return $this->hasMany(AccessRight::class, 'fk_grantor_owner_id');
    }

    public function receivedAccessRights(): HasMany
    {
        return $this->hasMany(AccessRight::class, 'fk_recipient_owner_id');
    }

    public function communityPosts(): HasMany
    {
        return $this->hasMany(CommunityPost::class, 'garden_owner_id', 'id');
    }
}
