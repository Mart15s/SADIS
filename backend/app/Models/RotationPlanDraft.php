<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RotationPlanDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'plot_id',
        'garden_owner_id',
        'planning_date',
        'plan',
    ];

    protected function casts(): array
    {
        return [
            'planning_date' => 'date',
            'plan' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'plot_id');
    }

    public function gardenOwner(): BelongsTo
    {
        return $this->belongsTo(GardenOwner::class, 'garden_owner_id');
    }
}
