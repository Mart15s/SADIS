<?php

namespace App\Models;

use App\Enums\TaskState;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'date',
        'name',
        'task_type',
        'type',
        'priority',
        'reason',
        'comment',
        'item',
        'item_quantity',
        'weather_context',
        'inventory_context',
        'simulated_state',
        'state',
        'status',
        'task_calendar_id',
        'fk_task_calendar_id',
        'plant_id',
        'fk_plant_id',
        'plant_zone_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'item_quantity' => 'decimal:2',
            'task_type' => 'string',
            'type' => 'string',
            'priority' => TaskPriority::class,
            'weather_context' => 'array',
            'inventory_context' => 'array',
            'simulated_state' => 'array',
            'state' => TaskState::class,
            'status' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            $task->task_calendar_id ??= $task->fk_task_calendar_id;
            $task->fk_task_calendar_id ??= $task->task_calendar_id;
            $task->plant_id ??= $task->fk_plant_id;
            $task->fk_plant_id ??= $task->plant_id;

            if ($task->state instanceof TaskState) {
                $task->status = $task->state->value;
            } elseif ($task->status) {
                $task->state = TaskState::normalize($task->status);
                $task->status = TaskState::normalize($task->status);
            } elseif ($task->state) {
                $task->status = is_string($task->state) ? TaskState::normalize($task->state) : $task->state->value;
            }

            $normalizedTaskType = TaskType::normalize(
                $task->task_type instanceof TaskType
                    ? $task->task_type->value
                    : ($task->task_type ?? $task->type)
            );

            $task->task_type = $normalizedTaskType;
            $task->type = $normalizedTaskType;
            $task->priority = $task->priority instanceof TaskPriority
                ? $task->priority
                : TaskPriority::normalize($task->priority);
        });
    }

    public function taskCalendar(): BelongsTo
    {
        return $this->belongsTo(TaskCalendar::class, 'task_calendar_id');
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }

    public function plantZone(): BelongsTo
    {
        return $this->belongsTo(PlantZone::class, 'plant_zone_id');
    }

    public function plantZones(): BelongsToMany
    {
        return $this->belongsToMany(PlantZone::class, 'used_on', 'fk_task_id', 'fk_plant_zone_id', 'id', 'id')
            ->withPivot('fk_plot_id');
    }

    public function usedOn(): HasMany
    {
        return $this->hasMany(UsedOn::class, 'fk_task_id');
    }

    public function harvestRecords(): HasMany
    {
        return $this->hasMany(HarvestRecord::class, 'task_id');
    }

    public function requiredResources(): HasMany
    {
        return $this->hasMany(TaskResourceRequirement::class);
    }

    public function inventoryUsageLogs(): HasMany
    {
        return $this->hasMany(InventoryUsageLog::class);
    }
}
