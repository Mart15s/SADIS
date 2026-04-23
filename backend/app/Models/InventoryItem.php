<?php

namespace App\Models;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'garden_owner_id',
        'name',
        'normalized_name',
        'quantity',
        'inventory_item_type',
        'type',
        'unit',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'inventory_item_type' => InventoryItemType::class,
            'type' => InventoryItemType::class,
            'unit' => InventoryUnit::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (InventoryItem $inventoryItem): void {
            $inventoryItem->inventory_item_type ??= $inventoryItem->type;
            $inventoryItem->type ??= $inventoryItem->inventory_item_type instanceof InventoryItemType
                ? $inventoryItem->inventory_item_type->value
                : $inventoryItem->inventory_item_type;
            $inventoryItem->normalized_name = mb_strtolower(trim((string) $inventoryItem->name));
            $inventoryItem->unit ??= InventoryUnit::Unit;
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'garden_owner_id');
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(GardenOwner::class, 'has_inventory', 'fk_inventory_item_id', 'fk_owner_id', 'id', 'id_user')
            ->withPivot('fk_profile_id');
    }

    public function inventoryLinks(): HasMany
    {
        return $this->hasMany(HasInventory::class, 'fk_inventory_item_id');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(InventoryUsageLog::class);
    }
}
