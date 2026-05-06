<?php

use App\Services\Inventory\InventoryPlanningRepairService;
use App\Services\Plant\PlantCareRepairService;
use App\Services\Calendar\WeatherForecastRepairService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('plant-care:repair-shared-links', function (PlantCareRepairService $repairService) {
    $summary = $repairService->repair();

    $this->info('Plant care shared-link repair completed.');
    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Repair catalog plant care links and realign planted records to shared catalog care.');

Artisan::command('inventory:repair-calendar-resources', function (InventoryPlanningRepairService $repairService) {
    $summary = $repairService->repair();

    $this->info('Inventory calendar resource repair completed.');
    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Normalize known inventory resources and repair day-level replenishment planning for existing calendars.');

Artisan::command('weather:repair-forecasts {--calendar-id=} {--dry-run}', function (WeatherForecastRepairService $repairService) {
    $calendarId = $this->option('calendar-id');
    $summary = $repairService->repair(
        $calendarId !== null ? (int) $calendarId : null,
        (bool) $this->option('dry-run'),
    );

    $this->info('Weather forecast repair completed.');
    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Refresh suspicious daily weather forecast rows for existing calendars using the current Meteo.lt pipeline.');
