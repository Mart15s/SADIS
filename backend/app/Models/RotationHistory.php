<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RotationHistory extends Model
{
    use HasFactory;

    protected $table = 'rotation_history';

    public $timestamps = false;

    protected $fillable = [
        'plant_zone_id',
        'from_date',
        'to_date',
        'fk_plot_id',
        'fk_plant_zone_id',
        'fk_plot_via_zone',
        'fk_plant_id',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RotationHistory $rotationHistory): void {
            $rotationHistory->plant_zone_id ??= $rotationHistory->fk_plant_zone_id;
            $rotationHistory->fk_plant_zone_id ??= $rotationHistory->plant_zone_id;
        });
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'fk_plot_id');
    }

    public function plotViaZone(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'fk_plot_via_zone');
    }

    public function plantZone(): BelongsTo
    {
        return $this->belongsTo(PlantZone::class, 'plant_zone_id');
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'fk_plant_id');
    }
}
