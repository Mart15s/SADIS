<?php

namespace App\Http\Resources\Calendar;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskCalendarListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'creation_date' => $this->creation_date?->toIso8601String(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'fk_plot_id' => $this->fk_plot_id,
            'tasks_count' => $this->whenCounted('tasks'),
        ];
    }
}
