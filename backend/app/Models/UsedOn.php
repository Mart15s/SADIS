<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsedOn extends Model
{
    use HasFactory;

    protected $table = 'used_on';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'fk_plant_zone_id',
        'fk_plot_id',
        'fk_task_id',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function plantZone(): BelongsTo
    {
        return $this->belongsTo(PlantZone::class, 'fk_plant_zone_id');
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'fk_plot_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'fk_task_id');
    }
}
