<?php

namespace App\Services\Calendar;

use App\Models\TaskCalendar;
use App\Models\WeatherForecast;
use App\Services\Integrations\MeteoLtClient;
use App\ValueObjects\WeatherData;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WeatherService
{
    public const SOURCE_API = 'api';

    public const SOURCE_STORED_CITY_DATE = 'stored_city_date';

    public const SOURCE_STORED_OTHER_CITY_DATE = 'stored_other_city_date';

    public const SOURCE_SEASONAL = 'seasonal';

    public function __construct(
        private readonly MeteoLtClient $meteoLtClient,
    ) {}

    public function getForecastRange(string $city, Carbon $start, Carbon $end): array
    {
        $liveFetchFailed = false;

        try {
            $dailyForecasts = $this->fetchDailyForecasts($city);
        } catch (Throwable $exception) {
            $liveFetchFailed = true;
            Log::warning('Failed to fetch Meteo.lt forecast.', [
                'city' => $city,
                'error' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);

            $dailyForecasts = [];
        }

        $result = [];
        $sourceSummary = [];

        foreach (CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()) as $date) {
            $dateKey = $date->toDateString();
            $weatherData = $dailyForecasts[$dateKey] ?? $this->storedFallbackForDate($city, $date);
            $result[$dateKey] = $weatherData->toArray();
            $sourceSummary[$weatherData->source] = ($sourceSummary[$weatherData->source] ?? 0) + 1;
        }

        if ($liveFetchFailed || count($sourceSummary) > 1 || ! isset($sourceSummary[self::SOURCE_API])) {
            Log::info('Resolved weather forecast range with fallback metadata.', [
                'city' => $city,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'sources' => $sourceSummary,
            ]);
        }

        return $result;
    }

    public function refreshCalendarForecasts(TaskCalendar $calendar, bool $force = false): array
    {
        $calendar->loadMissing('plot', 'weatherForecasts');

        if (! $force && ! $this->calendarForecastIsStale($calendar)) {
            return [
                'refreshed' => false,
                'updated_count' => 0,
                'fetched_at' => $this->latestFetchedAt($calendar)?->toIso8601String(),
                'message' => 'Orų prognozė dar galioja.',
            ];
        }

        $city = trim((string) $calendar->plot?->city);

        if ($city === '') {
            throw new RuntimeException('Calendar plot city is required to refresh weather forecasts.');
        }

        $dailyForecasts = $this->fetchDailyForecasts($city);
        $fetchedAt = now();
        $updatedCount = 0;

        foreach (CarbonPeriod::create($calendar->start_date->copy()->startOfDay(), $calendar->end_date->copy()->startOfDay()) as $date) {
            $dateKey = $date->toDateString();
            $weatherData = $dailyForecasts[$dateKey] ?? null;

            if (! $weatherData) {
                continue;
            }

            WeatherForecast::query()->updateOrCreate(
                [
                    'task_calendar_id' => $calendar->id,
                    'date' => $dateKey,
                ],
                [
                    'fk_task_calendar_id' => $calendar->id,
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
                    'city' => $city,
                    'fetched_at' => $fetchedAt,
                ]
            );

            $updatedCount++;
        }

        $calendar->unsetRelation('weatherForecasts');

        return [
            'refreshed' => $updatedCount > 0,
            'updated_count' => $updatedCount,
            'fetched_at' => $fetchedAt->toIso8601String(),
            'message' => $updatedCount > 0
                ? 'Orų prognozė atnaujinta iš Meteo.lt.'
                : 'Meteo.lt nepateikė šio kalendoriaus datų prognozės.',
        ];
    }

    public function calendarForecastIsStale(TaskCalendar $calendar): bool
    {
        $calendar->loadMissing('weatherForecasts');

        if ($calendar->weatherForecasts->isEmpty()) {
            return true;
        }

        $latestFetchedAt = $this->latestFetchedAt($calendar);

        if (! $latestFetchedAt) {
            return true;
        }

        return $latestFetchedAt->lte(now()->subMinutes($this->forecastTtlMinutes()));
    }

    private function fetchDailyForecasts(string $city): array
    {
        $place = $this->meteoLtClient->findPlaceByCity($city);
        $forecast = $this->meteoLtClient->getLongTermForecast((string) ($place['code'] ?? ''));
        $entries = $forecast['forecastTimestamps'] ?? null;

        if (! is_array($entries) || $entries === []) {
            throw new RuntimeException('Malformed Meteo.lt forecast response.');
        }

        $groupedEntries = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $timestamp = $entry['forecastTimeUtc'] ?? null;

            if (! is_string($timestamp) || trim($timestamp) === '') {
                continue;
            }

            $dateKey = Carbon::parse($timestamp, 'UTC')->toDateString();
            $groupedEntries[$dateKey][] = $entry;
        }

        if ($groupedEntries === []) {
            throw new RuntimeException('Meteo.lt forecast response did not contain usable forecast timestamps.');
        }

        $dailyForecasts = [];

        foreach ($groupedEntries as $dateKey => $dayEntries) {
            $dailyForecasts[$dateKey] = $this->aggregateDay(collect($dayEntries));
        }

        return $dailyForecasts;
    }

    private function latestFetchedAt(TaskCalendar $calendar): ?Carbon
    {
        return $calendar->weatherForecasts
            ->pluck('fetched_at')
            ->filter()
            ->max();
    }

    private function forecastTtlMinutes(): int
    {
        return max(1, (int) config('services.meteo_lt.forecast_ttl_minutes', 60));
    }

    private function aggregateDay(Collection $entries): WeatherData
    {
        $temps = [];
        $humidities = [];
        $rain = 0.0;
        $wind = 0.0;
        $conditionCodes = [];

        foreach ($entries as $entry) {
            $temp = data_get($entry, 'airTemperature');
            $humidity = data_get($entry, 'relativeHumidity');

            if ($temp !== null) {
                $temps[] = (float) $temp;
            }

            if ($humidity !== null) {
                $humidities[] = (float) $humidity;
            }

            $rain += (float) data_get($entry, 'totalPrecipitation', 0);
            $wind = max($wind, (float) data_get($entry, 'windSpeed', 0) * 3.6);

            $conditionCode = data_get($entry, 'conditionCode');

            if (is_string($conditionCode) && trim($conditionCode) !== '') {
                $conditionCodes[] = $conditionCode;
            }
        }

        $averageTemp = $temps === [] ? 0.0 : array_sum($temps) / count($temps);
        $averageHumidity = $humidities === [] ? 0.0 : array_sum($humidities) / count($humidities);
        $tempMin = $temps === [] ? $averageTemp : min($temps);
        $tempMax = $temps === [] ? ($averageTemp ?: $tempMin) : max($temps);

        return new WeatherData(
            tempMin: round($tempMin, 2),
            tempMax: round($tempMax, 2),
            precipitationMm: round((float) $rain, 2),
            humidity: round($averageHumidity, 2),
            windKmh: round((float) $wind, 2),
            conditionCode: $this->selectConditionCode($conditionCodes),
            isSeasonalFallback: false,
            source: self::SOURCE_API,
        );
    }

    private function storedFallbackForDate(string $city, Carbon $date): WeatherData
    {
        $storedForecast = WeatherForecast::query()
            ->where('city', $city)
            ->whereDate('date', $date->toDateString())
            ->orderByDesc('id')
            ->first();

        if ($storedForecast) {
            return $this->weatherDataFromStoredForecast($storedForecast, self::SOURCE_STORED_CITY_DATE);
        }

        $storedForecast = WeatherForecast::query()
            ->whereDate('date', $date->toDateString())
            ->orderByRaw('CASE WHEN city = ? THEN 0 ELSE 1 END', [$city])
            ->orderByDesc('id')
            ->first();

        if ($storedForecast) {
            return $this->weatherDataFromStoredForecast($storedForecast, self::SOURCE_STORED_OTHER_CITY_DATE);
        }

        return $this->seasonalFallbackForDate($date);
    }

    private function seasonalFallbackForDate(Carbon $date): WeatherData
    {
        $profiles = [
            1 => ['temp_min' => -6.0, 'temp_max' => -1.0, 'precipitation_mm' => 1.8, 'humidity' => 86.0, 'wind_kmh' => 19.0, 'condition_code' => 'snow'],
            2 => ['temp_min' => -6.0, 'temp_max' => -1.0, 'precipitation_mm' => 1.6, 'humidity' => 84.0, 'wind_kmh' => 18.0, 'condition_code' => 'snow'],
            3 => ['temp_min' => -2.0, 'temp_max' => 5.0, 'precipitation_mm' => 1.7, 'humidity' => 76.0, 'wind_kmh' => 17.0, 'condition_code' => 'cloudy-with-sunny-intervals'],
            4 => ['temp_min' => 2.0, 'temp_max' => 11.0, 'precipitation_mm' => 1.5, 'humidity' => 68.0, 'wind_kmh' => 16.0, 'condition_code' => 'cloudy-with-sunny-intervals'],
            5 => ['temp_min' => 7.0, 'temp_max' => 18.0, 'precipitation_mm' => 1.9, 'humidity' => 66.0, 'wind_kmh' => 15.0, 'condition_code' => 'variable-cloudiness'],
            6 => ['temp_min' => 11.0, 'temp_max' => 22.0, 'precipitation_mm' => 2.3, 'humidity' => 68.0, 'wind_kmh' => 14.0, 'condition_code' => 'light-rain'],
            7 => ['temp_min' => 14.0, 'temp_max' => 24.0, 'precipitation_mm' => 2.4, 'humidity' => 70.0, 'wind_kmh' => 13.0, 'condition_code' => 'light-rain'],
            8 => ['temp_min' => 13.0, 'temp_max' => 23.0, 'precipitation_mm' => 2.1, 'humidity' => 72.0, 'wind_kmh' => 13.0, 'condition_code' => 'cloudy-with-sunny-intervals'],
            9 => ['temp_min' => 9.0, 'temp_max' => 17.0, 'precipitation_mm' => 1.9, 'humidity' => 78.0, 'wind_kmh' => 14.0, 'condition_code' => 'cloudy-with-sunny-intervals'],
            10 => ['temp_min' => 4.0, 'temp_max' => 10.0, 'precipitation_mm' => 1.8, 'humidity' => 83.0, 'wind_kmh' => 16.0, 'condition_code' => 'light-rain'],
            11 => ['temp_min' => 0.0, 'temp_max' => 5.0, 'precipitation_mm' => 1.8, 'humidity' => 87.0, 'wind_kmh' => 18.0, 'condition_code' => 'cloudy'],
            12 => ['temp_min' => -4.0, 'temp_max' => 0.0, 'precipitation_mm' => 1.9, 'humidity' => 88.0, 'wind_kmh' => 19.0, 'condition_code' => 'snow'],
        ];

        $profile = $profiles[(int) $date->month] ?? $profiles[6];

        return new WeatherData(
            tempMin: (float) $profile['temp_min'],
            tempMax: (float) $profile['temp_max'],
            precipitationMm: (float) $profile['precipitation_mm'],
            humidity: (float) $profile['humidity'],
            windKmh: (float) $profile['wind_kmh'],
            conditionCode: (string) $profile['condition_code'],
            isSeasonalFallback: true,
            source: self::SOURCE_SEASONAL,
        );
    }

    private function weatherDataFromStoredForecast(WeatherForecast $storedForecast, string $source): WeatherData
    {
        return new WeatherData(
            tempMin: (float) ($storedForecast->temp_min ?? $storedForecast->temperature),
            tempMax: (float) ($storedForecast->temp_max ?? $storedForecast->temperature),
            precipitationMm: (float) $storedForecast->precipitation,
            humidity: (float) $storedForecast->humidity,
            windKmh: (float) ($storedForecast->wind_kmh ?? 0.0),
            conditionCode: $storedForecast->condition_code,
            isSeasonalFallback: (bool) $storedForecast->is_seasonal_fallback,
            source: $source,
            sourceDate: optional($storedForecast->source_date)->toDateString()
                ?? optional($storedForecast->date)->toDateString(),
            sourceCity: $storedForecast->city,
        );
    }

    /**
     * @param  array<int, string>  $conditionCodes
     */
    private function selectConditionCode(array $conditionCodes): ?string
    {
        if ($conditionCodes === []) {
            return null;
        }

        usort($conditionCodes, function (string $left, string $right): int {
            $leftSeverity = $this->conditionSeverity($left);
            $rightSeverity = $this->conditionSeverity($right);

            if ($leftSeverity !== $rightSeverity) {
                return $rightSeverity <=> $leftSeverity;
            }

            return strcmp($left, $right);
        });

        return $conditionCodes[0];
    }

    private function conditionSeverity(string $conditionCode): int
    {
        $normalized = mb_strtolower($conditionCode);

        return match (true) {
            str_contains($normalized, 'thunder') => 6,
            str_contains($normalized, 'snow'), str_contains($normalized, 'sleet'), str_contains($normalized, 'hail') => 5,
            str_contains($normalized, 'storm'), str_contains($normalized, 'heavy') => 4,
            str_contains($normalized, 'rain') => 3,
            str_contains($normalized, 'cloud') => 2,
            default => 1,
        };
    }
}
