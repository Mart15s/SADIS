<?php

namespace App\Models;

use App\Enums\PlantType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogPlant extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'canonical_name',
        'plant_type',
        'fk_plant_care_id',
        'description',
        'source_provider',
        'source_quality',
        'source_scientific_name',
        'source_family',
        'source_image_url',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'plant_type' => PlantType::class,
            'metadata' => 'array',
        ];
    }

    public function plantCare(): BelongsTo
    {
        return $this->belongsTo(PlantCare::class, 'fk_plant_care_id');
    }

    public function plants(): HasMany
    {
        return $this->hasMany(Plant::class, 'fk_catalog_plant_id');
    }
}
