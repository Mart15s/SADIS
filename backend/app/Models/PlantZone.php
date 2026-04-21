<?php

namespace App\Models;

use App\Enums\SoilType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantZone extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'plot_id',
        'name',
        'zone_size',
        'soil_type',
        'rotation_stage',
        'last_planting_date',
        'fk_plot_id',
        'geometry',
    ];

    protected function casts(): array
    {
        return [
            'zone_size' => 'decimal:2',
            'soil_type' => SoilType::class,
            'last_planting_date' => 'date',
            'geometry' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PlantZone $plantZone): void {
            $plantZone->plot_id ??= $plantZone->fk_plot_id;
            $plantZone->fk_plot_id ??= $plantZone->plot_id;
        });
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'plot_id');
    }

    public function plants(): HasMany
    {
        return $this->hasMany(Plant::class, 'plant_zone_id');
    }

    public function rotationHistory(): HasMany
    {
        return $this->hasMany(RotationHistory::class, 'fk_plant_zone_id');
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'used_on', 'fk_plant_zone_id', 'fk_task_id', 'id', 'id')
            ->withPivot('fk_plot_id')
            ->wherePivot('fk_plot_id', $this->fk_plot_id);
    }

    public function usedOn(): HasMany
    {
        return $this->hasMany(UsedOn::class, 'fk_plant_zone_id');
    }
}
