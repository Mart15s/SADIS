<?php

namespace App\Models;

use App\Enums\ConditionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantConditionHistory extends Model
{
    use HasFactory;

    protected $table = 'plant_condition_history';

    public $timestamps = false;

    protected $fillable = [
        'plant_id',
        'measured_at',
        'notes',
        'photo_url',
        'condition',
        'condition_type',
        'fk_plant_id',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'condition' => ConditionType::class,
            'condition_type' => ConditionType::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PlantConditionHistory $history): void {
            $history->plant_id ??= $history->fk_plant_id;
            $history->fk_plant_id ??= $history->plant_id;
            $history->condition_type ??= $history->condition?->value ?? $history->condition;
            $history->condition ??= $history->condition_type?->value ?? $history->condition_type;
        });
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }
}
