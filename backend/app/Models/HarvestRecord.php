<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarvestRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'plot_id',
        'plant_id',
        'task_id',
        'garden_owner_id',
        'quantity',
        'harvested_on',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'harvested_on' => 'date',
        ];
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'plot_id');
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function gardenOwner(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'garden_owner_id');
    }
}
