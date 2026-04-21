<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeatherForecast extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'task_calendar_id',
        'date',
        'temperature',
        'temp_min',
        'temp_max',
        'precipitation',
        'humidity',
        'wind_kmh',
        'condition_code',
        'is_seasonal_fallback',
        'source',
        'source_date',
        'source_city',
        'city',
        'fk_task_calendar_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'temperature' => 'decimal:2',
            'temp_min' => 'decimal:2',
            'temp_max' => 'decimal:2',
            'precipitation' => 'decimal:2',
            'humidity' => 'decimal:2',
            'wind_kmh' => 'decimal:2',
            'condition_code' => 'string',
            'is_seasonal_fallback' => 'boolean',
            'source' => 'string',
            'source_date' => 'date',
            'source_city' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (WeatherForecast $forecast): void {
            $forecast->task_calendar_id ??= $forecast->fk_task_calendar_id;
            $forecast->fk_task_calendar_id ??= $forecast->task_calendar_id;
        });
    }

    public function taskCalendar(): BelongsTo
    {
        return $this->belongsTo(TaskCalendar::class, 'task_calendar_id');
    }
}
