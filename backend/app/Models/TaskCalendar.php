<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskCalendar extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'creation_date',
        'start_date',
        'end_date',
        'plot_id',
        'fk_plot_id',
    ];

    protected function casts(): array
    {
        return [
            'creation_date' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TaskCalendar $taskCalendar): void {
            $taskCalendar->plot_id ??= $taskCalendar->fk_plot_id;
            $taskCalendar->fk_plot_id ??= $taskCalendar->plot_id;
        });
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class, 'plot_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'task_calendar_id');
    }

    public function weatherForecasts(): HasMany
    {
        return $this->hasMany(WeatherForecast::class, 'task_calendar_id');
    }
}
