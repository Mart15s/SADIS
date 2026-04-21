<?php

namespace App\Http\Controllers\Api\Calendar;

use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\CompleteTaskRequest;
use App\Http\Requests\Calendar\ListCalendarTasksRequest;
use App\Http\Requests\Calendar\RejectTaskRequest;
use App\Http\Resources\Calendar\TaskResource;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Services\AccessService;
use App\Services\InventoryService;
use App\Services\TaskWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use AuthorizesPlotAccess;

    public function index(
        ListCalendarTasksRequest $request,
        TaskCalendar $calendar,
        AccessService $accessService,
        InventoryService $inventoryService,
    ): JsonResponse
    {
        $this->authorizeCalendarView($request, $calendar, $accessService);
        $calendar->loadMissing('plot.gardenOwner');

        $tasks = $calendar->tasks()
            ->with(['plant.plantZone', 'plantZone', 'requiredResources'])
            ->when($request->validated('date'), function ($query, $date) {
                $query->whereDate('date', $date);
            })
            ->when($request->validated('plant_id'), function ($query, $plantId) {
                $query->where('plant_id', $plantId);
            })
            ->when($request->validated('zone_id'), function ($query, $zoneId) {
                $query->where('plant_zone_id', $zoneId);
            })
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $inventoryService->attachLiveTaskInventory($calendar->plot?->gardenOwner, $tasks);

        return TaskResource::collection($tasks)->response();
    }

    public function complete(
        CompleteTaskRequest $request,
        Task $task,
        TaskWorkflowService $service,
        AccessService $accessService
    ): JsonResponse {
        $this->authorizeTaskEdit($request, $task, $accessService);

        $updatedTask = $service->complete(
            $task,
            $request->validated('materials_used')
        );

        return response()->json([
            'message' => 'Veiksmas sėkmingai įvykdytas',
            'task' => TaskResource::make($updatedTask),
        ]);
    }

    public function reject(
        RejectTaskRequest $request,
        Task $task,
        TaskWorkflowService $service,
        AccessService $accessService
    ): JsonResponse {
        $this->authorizeTaskEdit($request, $task, $accessService);

        $updatedTask = $service->reject(
            $task,
            $request->validated('reason')
        );

        return response()->json([
            'message' => 'Veiksmas sėkmingai atšauktas',
            'task' => TaskResource::make($updatedTask),
        ]);
    }

    private function authorizeCalendarView(
        Request $request,
        TaskCalendar $calendar,
        AccessService $accessService
    ): void
    {
        $calendar->loadMissing('plot');
        abort_unless($calendar->plot, 404);

        $this->ensureUserCanViewPlot($request, $calendar->plot, $accessService);
    }

    private function authorizeTaskEdit(Request $request, Task $task, AccessService $accessService): void
    {
        $task->loadMissing('taskCalendar.plot');
        abort_unless($task->taskCalendar?->plot, 404);

        $this->ensureUserCanEditPlot($request, $task->taskCalendar->plot, $accessService);
    }
}
