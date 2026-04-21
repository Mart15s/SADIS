<?php

namespace App\Services;

use App\Models\Plot;
use App\Models\TaskCalendar;
use Carbon\Carbon;

class TaskCalendarService
{
    public function __construct(
        private readonly CalendarGenerationService $calendarGenerationService,
    ) {
    }

    public function generate(Plot $plot, Carbon $start, Carbon $end): TaskCalendar
    {
        return $this->calendarGenerationService->generateCalendar($plot, $start, $end);
    }
}
