<?php

namespace App\Models;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskResourceRequirement extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'resource_name',
        'normalized_name',
        'inventory_item_type',
        'unit',
        'required_quantity',
        'shortage_quantity',
        'is_consumed',
    ];

    protected function casts(): array
    {
        return [
            'inventory_item_type' => InventoryItemType::class,
            'unit' => InventoryUnit::class,
            'required_quantity' => 'decimal:2',
            'shortage_quantity' => 'decimal:2',
            'is_consumed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TaskResourceRequirement $requirement): void {
            $requirement->normalized_name = mb_strtolower(trim((string) $requirement->resource_name));
            $requirement->shortage_quantity = round(max(0, (float) $requirement->shortage_quantity), 2);
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(InventoryUsageLog::class, 'task_resource_requirement_id');
    }
}
