<?php

namespace App\Http\Resources\Calendar;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskCalendarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'creation_date' => $this->creation_date?->toIso8601String(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'fk_plot_id' => $this->fk_plot_id,
            'available_dates' => $this->start_date && $this->end_date
                ? collect(\Carbon\CarbonPeriod::create($this->start_date, $this->end_date))
                    ->map(fn ($date) => $date->toDateString())
                    ->values()
                    ->all()
                : [],
            'weather' => WeatherForecastResource::collection($this->whenLoaded('weatherForecasts')),
            'day_resource_summary' => $this->day_resource_summary ?? [],
            'tasks_by_date' => $this->whenLoaded('tasks', function () {
                return $this->tasks
                    ->sortBy(['date', 'id'])
                    ->groupBy(fn ($task) => $task->date->toDateString())
                    ->map(fn ($tasks) => TaskResource::collection($tasks)->resolve())
                    ->all();
            }),
        ];
    }
}
