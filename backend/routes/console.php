<?php

use App\Services\Inventory\InventoryPlanningRepairService;
use App\Services\Plant\PlantCareRepairService;
use App\Services\Calendar\WeatherForecastRepairService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\LegacyMigrationRun;
use App\Services\Yava\LegacyMigrationService;
use Database\Seeders\YavaStageOneDemoSeeder;

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

Artisan::command('yava:stage1-migrate {--execute : Persist target records} {--run= : Resume a prior run UUID} {--chunk=250 : Records per chunk} {--limit= : Pause after this many plant records}', function (LegacyMigrationService $service) {
    $run = $service->run(
        (bool) $this->option('execute'),
        (int) $this->option('chunk'),
        $this->option('run') ?: null,
        $this->option('limit') !== null ? (int) $this->option('limit') : null,
    );
    $this->info($run->dry_run ? 'Yava Stage 1 dry run finished.' : 'Yava Stage 1 migration finished.');
    $this->line(json_encode($run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Classify and optionally transform legacy plots/plants using chunked, auditable, resumable rules.');

Artisan::command('yava:stage1-report {run? : Migration run UUID}', function (LegacyMigrationService $service, ?string $run = null) {
    $payload = ['counts' => $service->counts()];
    if ($run) {
        $payload['run'] = LegacyMigrationRun::query()->findOrFail($run)->toArray();
        $payload['classifications'] = \App\Models\LegacyRecordMapping::query()
            ->where('migration_run_id', $run)->selectRaw('classification, status, COUNT(*) as records')
            ->groupBy('classification', 'status')->orderBy('classification')->get()->toArray();
    }
    $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Report Stage 1 source/target counts, mappings and orphan checks.');

Artisan::command('yava:stage1-demo', function () {
    $this->call('db:seed', ['--class' => YavaStageOneDemoSeeder::class, '--force' => true]);
})->purpose('Create deterministic Yava Stage 1 demonstration data.');
