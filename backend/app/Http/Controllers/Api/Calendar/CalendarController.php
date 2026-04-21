<?php

namespace App\Http\Controllers\Api\Calendar;

use App\Exceptions\CalendarGenerationException;
use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\GenerateCalendarRequest;
use App\Http\Resources\Calendar\TaskCalendarListResource;
use App\Http\Resources\Calendar\TaskCalendarResource;
use App\Models\Plot;
use App\Models\TaskCalendar;
use App\Services\AccessService;
use App\Services\InventoryService;
use App\Services\TaskCalendarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    use AuthorizesPlotAccess;

    public function index(Request $request, Plot $plot, AccessService $accessService): JsonResponse
    {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);

        $calendars = $plot->taskCalendars()
            ->withCount('tasks')
            ->orderByDesc('creation_date')
            ->get();

        return response()->json(TaskCalendarListResource::collection($calendars));
    }

    public function store(
        GenerateCalendarRequest $request,
        Plot $plot,
        TaskCalendarService $service,
        InventoryService $inventoryService,
        AccessService $accessService
    ): JsonResponse
    {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        try {
            $calendar = $service->generate(
                $plot,
                Carbon::parse($request->validated('start_date'))->startOfDay(),
                Carbon::parse($request->validated('end_date'))->startOfDay(),
            );
        } catch (CalendarGenerationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->hydrateCalendarInventory($calendar, $inventoryService);

        return response()->json(
            TaskCalendarResource::make($calendar),
            201
        );
    }

    public function show(
        Request $request,
        Plot $plot,
        TaskCalendar $calendar,
        AccessService $accessService,
        InventoryService $inventoryService
    ): JsonResponse
    {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);
        abort_unless($calendar->fk_plot_id === $plot->id, 404);

        $calendar->load([
            'tasks.plant.plantZone',
            'tasks.plantZone',
            'tasks.requiredResources',
            'weatherForecasts',
        ]);
        $this->hydrateCalendarInventory($calendar, $inventoryService);

        return response()->json(TaskCalendarResource::make($calendar));
    }

    private function hydrateCalendarInventory(TaskCalendar $calendar, InventoryService $inventoryService): void
    {
        $calendar->loadMissing([
            'plot.gardenOwner',
            'tasks.plant.plantZone',
            'tasks.plantZone',
            'tasks.requiredResources',
        ]);

        $dayResourceSummary = $inventoryService->attachLiveTaskInventory(
            $calendar->plot?->gardenOwner,
            $calendar->tasks,
        );

        $calendar->setAttribute('day_resource_summary', $dayResourceSummary);
    }
}
