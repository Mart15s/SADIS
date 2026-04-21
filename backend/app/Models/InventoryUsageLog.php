<?php

namespace App\Models;

use App\Enums\InventoryUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryUsageLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'inventory_item_id',
        'task_id',
        'task_resource_requirement_id',
        'garden_owner_id',
        'change_type',
        'quantity_before',
        'quantity_delta',
        'quantity_after',
        'unit',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_before' => 'decimal:2',
            'quantity_delta' => 'decimal:2',
            'quantity_after' => 'decimal:2',
            'unit' => InventoryUnit::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function taskResourceRequirement(): BelongsTo
    {
        return $this->belongsTo(TaskResourceRequirement::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'garden_owner_id');
    }
}
