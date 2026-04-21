<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GardenOwner extends Model
{
    use HasFactory;

    protected $table = 'garden_owners';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'user_id',
        'id_user',
        'fk_profile_id',
    ];

    protected function casts(): array
    {
        return [];
    }

    protected static function booted(): void
    {
        static::saving(function (GardenOwner $gardenOwner): void {
            $gardenOwner->user_id ??= $gardenOwner->id ?? $gardenOwner->id_user;
            $gardenOwner->id ??= $gardenOwner->user_id ?? $gardenOwner->id_user;
            $gardenOwner->id_user ??= $gardenOwner->user_id;
        });

        static::saved(function (GardenOwner $gardenOwner): void {
            if ($gardenOwner->fk_profile_id && $gardenOwner->user_id) {
                Profile::query()
                    ->whereKey($gardenOwner->fk_profile_id)
                    ->update(['user_id' => $gardenOwner->user_id]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'fk_profile_id');
    }

    public function plots(): BelongsToMany
    {
        return $this->belongsToMany(Plot::class, 'has_plot', 'fk_owner_id', 'fk_plot_id', 'id_user', 'id')
            ->withPivot('fk_profile_id')
            ->wherePivot('fk_profile_id', $this->fk_profile_id);
    }

    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'has_inventory', 'fk_owner_id', 'fk_inventory_item_id', 'id_user', 'id')
            ->withPivot('fk_profile_id')
            ->wherePivot('fk_profile_id', $this->fk_profile_id);
    }

    public function ownedPlots(): HasMany
    {
        return $this->hasMany(Plot::class, 'garden_owner_id');
    }

    public function ownedInventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'garden_owner_id');
    }

    public function grantedAccessRights(): HasMany
    {
        return $this->hasMany(AccessRight::class, 'fk_grantor_owner_id', 'id_user');
    }

    public function receivedAccessRights(): HasMany
    {
        return $this->hasMany(AccessRight::class, 'fk_recipient_owner_id', 'id_user');
    }

    public function communityPosts(): HasMany
    {
        return $this->hasMany(CommunityPost::class, 'garden_owner_id');
    }

    public function harvestRecords(): HasMany
    {
        return $this->hasMany(HarvestRecord::class, 'garden_owner_id');
    }

    public function plotLinks(): HasMany
    {
        return $this->hasMany(HasPlot::class, 'fk_owner_id', 'id_user');
    }

    public function inventoryLinks(): HasMany
    {
        return $this->hasMany(HasInventory::class, 'fk_owner_id', 'id_user');
    }
}
