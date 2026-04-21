<?php

namespace App\Services;

use App\Models\GardenOwner;
use App\Models\HarvestRecord;
use App\Models\Plant;
use App\Models\Plot;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HarvestService
{
    /**
     * @return Collection<int, HarvestRecord>
     */
    public function listForPlot(Plot $plot, ?GardenOwner $owner = null, mixed $plantId = null): Collection
    {
        unset($owner);

        return HarvestRecord::query()
            ->with(['plant.plantZone', 'task'])
            ->where('plot_id', $plot->id)
            ->when($plantId, fn ($query, $resolvedPlantId) => $query->where('plant_id', (int) $resolvedPlantId))
            ->orderByDesc('harvested_on')
            ->orderByDesc('id')
            ->get();
    }

    public function registerForPlot(Plot $plot, GardenOwner $owner, array $data): HarvestRecord
    {
        $plant = Plant::query()
            ->whereKey($data['plant_id'])
            ->where('fk_plot_id', $plot->id)
            ->first();

        if (! $plant) {
            throw ValidationException::withMessages([
                'plant_id' => ['Pasirinktas augalas nepriklauso siam sklypui.'],
            ]);
        }

        $task = null;

        if (! empty($data['task_id'])) {
            $task = Task::query()
                ->whereKey($data['task_id'])
                ->whereHas('taskCalendar', fn ($query) => $query->where('plot_id', $plot->id))
                ->first();

            if (! $task) {
                throw ValidationException::withMessages([
                    'task_id' => ['Pasirinkta uzduotis nepriklauso siam sklypui.'],
                ]);
            }

            if (($task->task_type ?? $task->type) !== 'harvest') {
                throw ValidationException::withMessages([
                    'task_id' => ['Su derliaus irasu galima sieti tik derliaus uzduoti.'],
                ]);
            }

            if ((int) ($task->plant_id ?? $task->fk_plant_id) !== (int) $plant->id) {
                throw ValidationException::withMessages([
                    'task_id' => ['Pasirinkta uzduotis nesutampa su pasirinktu augalu.'],
                ]);
            }
        }

        return DB::transaction(function () use ($plot, $owner, $plant, $task, $data) {
            $record = HarvestRecord::query()->create([
                'plot_id' => $plot->id,
                'plant_id' => $plant->id,
                'task_id' => $task?->id,
                'garden_owner_id' => $owner->id,
                'quantity' => $data['quantity'],
                'harvested_on' => $data['harvested_on'],
                'notes' => $data['notes'] ?? null,
            ]);

            return $record->fresh(['plant.plantZone', 'task']);
        });
    }
}
