<?php

namespace App\Models;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\TaskType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlantCare extends Model
{
    use HasFactory;

    protected $table = 'plant_care';

    public $timestamps = false;

    protected $fillable = [
        'description',
        'conditions',
        'growing_duration_days',
        'flowering_duration_days',
        'germinating_duration_days',
        'mature_duration_days',
        'mature_duration_end_days',
        'mature_end_duration_days',
        'regenerating_duration_days',
        'reusable',
        'plant_name',
        'canonical_name',
        'task_type',
        'plant_type',
        'condition',
        'watering_interval_days',
        'fertilizing_interval_days',
        'pest_check_interval_days',
        'rain_skip_threshold_mm',
        'frost_temp_threshold_c',
        'heat_extra_water_temp_c',
        'wind_protection_kmh',
        'source_provider',
        'source_quality',
        'source_perenual_species_id',
        'source_common_name',
        'source_scientific_name',
        'source_family',
        'source_image_url',
    ];

    protected function casts(): array
    {
        return [
            'growing_duration_days' => 'integer',
            'flowering_duration_days' => 'integer',
            'germinating_duration_days' => 'integer',
            'mature_duration_days' => 'integer',
            'mature_duration_end_days' => 'integer',
            'mature_end_duration_days' => 'integer',
            'regenerating_duration_days' => 'integer',
            'reusable' => 'boolean',
            'task_type' => TaskType::class,
            'plant_type' => PlantType::class,
            'condition' => ConditionType::class,
            'watering_interval_days' => 'integer',
            'fertilizing_interval_days' => 'integer',
            'pest_check_interval_days' => 'integer',
            'rain_skip_threshold_mm' => 'decimal:1',
            'frost_temp_threshold_c' => 'decimal:1',
            'heat_extra_water_temp_c' => 'decimal:1',
            'wind_protection_kmh' => 'decimal:1',
            'source_perenual_species_id' => 'integer',
        ];
    }

    public function plants(): HasManyThrough
    {
        return $this->hasManyThrough(
            Plant::class,
            CatalogPlant::class,
            'fk_plant_care_id',
            'fk_catalog_plant_id',
            'id',
            'id',
        );
    }

    public function catalogPlants(): HasMany
    {
        return $this->hasMany(CatalogPlant::class, 'fk_plant_care_id');
    }
}
