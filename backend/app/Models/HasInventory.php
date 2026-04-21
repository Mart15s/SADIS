<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HasInventory extends Model
{
    use HasFactory;

    protected $table = 'has_inventory';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'fk_inventory_item_id',
        'fk_owner_id',
        'fk_profile_id',
    ];

    protected function casts(): array
    {
        return [];
    }

    protected static function booted(): void
    {
        static::created(function (HasInventory $hasInventory): void {
            InventoryItem::query()
                ->whereKey($hasInventory->fk_inventory_item_id)
                ->whereNull('garden_owner_id')
                ->update(['garden_owner_id' => $hasInventory->fk_owner_id]);
        });
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'fk_inventory_item_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'fk_owner_id', 'id_user')
            ->whereColumn('garden_owners.fk_profile_id', 'has_inventory.fk_profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fk_owner_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'fk_profile_id');
    }
}
