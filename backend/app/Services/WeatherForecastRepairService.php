<?php

namespace App\Services;

use App\Models\TaskCalendar;
use App\Models\WeatherForecast;
use App\ValueObjects\WeatherData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class WeatherForecastRepairService
{
    public function __construct(
        private readonly WeatherService $weatherService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function repair(?int $calendarId = null, bool $dryRun = false): array
    {
        $summary = [
            'calendars_scanned' => 0,
            'suspicious_calendars' => 0,
            'repaired_calendars' => 0,
            'skipped_calendars' => 0,
            'errors' => [],
        ];

        $query = TaskCalendar::query()
            ->with(['plot', 'weatherForecasts'])
            ->when($calendarId !== null, fn ($builder) => $builder->whereKey($calendarId));

        foreach ($query->get() as $calendar) {
            $summary['calendars_scanned']++;

            if (! $this->isSuspicious($calendar->weatherForecasts)) {
                continue;
            }

            $summary['suspicious_calendars']++;

            if (! $calendar->plot || ! $calendar->start_date || ! $calendar->end_date) {
                $summary['skipped_calendars']++;
                $summary['errors'][] = [
                    'calendar_id' => $calendar->id,
                    'reason' => 'Calendar is missing plot or date range metadata.',
                ];

                continue;
            }

            try {
                $weatherByDate = $this->weatherService->getForecastRange(
                    $calendar->plot->city,
                    Carbon::parse($calendar->start_date)->startOfDay(),
                    Carbon::parse($calendar->end_date)->startOfDay(),
                );

                if ($dryRun) {
                    $summary['repaired_calendars']++;
                    continue;
                }

                DB::transaction(function () use ($calendar, $weatherByDate): void {
                    $calendar->weatherForecasts()->delete();

                    foreach ($weatherByDate as $date => $row) {
                        $weatherData = WeatherData::fromArray($row);

                        WeatherForecast::query()->create([
                            'date' => $date,
                            'temperature' => $weatherData->averageTemperature(),
                            'temp_min' => $weatherData->tempMin,
                            'temp_max' => $weatherData->tempMax,
                            'precipitation' => $weatherData->precipitationMm,
                            'humidity' => $weatherData->humidity,
                            'wind_kmh' => $weatherData->windKmh,
                            'condition_code' => $weatherData->conditionCode,
                            'is_seasonal_fallback' => $weatherData->isSeasonalFallback,
                            'source' => $weatherData->source,
                            'source_date' => $weatherData->sourceDate,
                            'source_city' => $weatherData->sourceCity,
                            'city' => $calendar->plot->city,
                            'task_calendar_id' => $calendar->id,
                            'fk_task_calendar_id' => $calendar->id,
                        ]);
                    }
                });

                $summary['repaired_calendars']++;
            } catch (Throwable $exception) {
                $summary['skipped_calendars']++;
                $summary['errors'][] = [
                    'calendar_id' => $calendar->id,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * @param  Collection<int, WeatherForecast>  $forecasts
     */
    private function isSuspicious(Collection $forecasts): bool
    {
        if ($forecasts->count() <= 1) {
            return false;
        }

        $signatures = $forecasts
            ->map(function (WeatherForecast $forecast): string {
                return implode('|', [
                    (string) $forecast->temp_min,
                    (string) $forecast->temp_max,
                    (string) $forecast->precipitation,
                    (string) $forecast->wind_kmh,
                    (string) $forecast->condition_code,
                    $forecast->is_seasonal_fallback ? '1' : '0',
                ]);
            });

        $hasLegacyUnknown = $forecasts->contains(fn (WeatherForecast $forecast): bool => (string) $forecast->source === 'legacy_unknown');

        if (! $hasLegacyUnknown) {
            return false;
        }

        $signatureCounts = $signatures->countBy()->sortDesc()->values();
        $dominantCount = (int) ($signatureCounts->first() ?? 0);

        if ($dominantCount === $forecasts->count()) {
            return true;
        }

        return $dominantCount >= 3 && $dominantCount >= (int) ceil($forecasts->count() * 0.4);
    }
}
