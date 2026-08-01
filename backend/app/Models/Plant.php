<?php

namespace App\Models;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plant extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'variety',
        'growing_time_days',
        'recommended_temperature',
        'recommended_humidity',
        'plant_date',
        'disease_notes',
        'disease',
        'rest_time_days',
        'plant_size',
        'quantity',
        'occupied_area',
        'season',
        'harvest_date',
        'notes',
        'marker_position_x',
        'marker_position_y',
        'photo_url',
        'reusable',
        'type',
        'condition',
        'fk_catalog_plant_id',
        'plant_zone_id',
        'fk_plant_zone_id',
        'fk_plot_id',
    ];

    protected function casts(): array
    {
        return [
            'recommended_temperature' => 'decimal:2',
            'recommended_humidity' => 'decimal:2',
            'plant_date' => 'date',
            'disease' => 'boolean',
            'plant_size' => 'decimal:2',
            'quantity' => 'decimal:2',
            'occupied_area' => 'decimal:2',
            'harvest_date' => 'date',
            'marker_position_x' => 'decimal:6',
            'marker_position_y' => 'decimal:6',
            'reusable' => 'boolean',
            'type' => PlantType::class,
            'condition' => ConditionType::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Plant $plant): void {
            $plant->plant_zone_id ??= $plant->fk_plant_zone_id;
            $plant->fk_plant_zone_id ??= $plant->plant_zone_id;
        });
    }

    public function plantZone(): BelongsTo
    {
        return $this->belongsTo(PlantZone::class, 'plant_zone_id');
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'fk_plot_id');
    }

    public function catalogPlant(): BelongsTo
    {
        return $this->belongsTo(CatalogPlant::class, 'fk_catalog_plant_id');
    }

    public function effectivePlantCare(): ?PlantCare
    {
        if ($this->relationLoaded('catalogPlant') && $this->catalogPlant?->relationLoaded('plantCare')) {
            return $this->catalogPlant->plantCare;
        }

        return $this->catalogPlant()->with('plantCare')->first()?->plantCare;
    }

    public function conditionHistory(): HasMany
    {
        return $this->hasMany(PlantConditionHistory::class, 'fk_plant_id');
    }

    public function rotationHistory(): HasMany
    {
        return $this->hasMany(RotationHistory::class, 'fk_plant_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'plant_id');
    }

    public function harvestRecords(): HasMany
    {
        return $this->hasMany(HarvestRecord::class, 'plant_id');
    }
}
