<?php

namespace App\Services\Calendar;

use App\Models\WeatherForecast;
use App\Services\Integrations\MeteoLtClient;
use App\ValueObjects\WeatherData;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
    ) {
    }

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

    /**
     * @return array<string, mixed>
     */
    public function debugForecast(string $city): array
    {
        $city = trim($city);

        if ($city === '') {
            throw new RuntimeException('City name is required.');
        }

        $placeMeta = null;
        $forecastMeta = null;

        try {
            $placeMeta = $this->rememberWithMeta(
                'dev-meteo-place:'.md5(mb_strtolower($city)),
                now()->addHours(6),
                fn (): array => $this->meteoLtClient->findPlaceByCity($city)
            );

            $place = $placeMeta['value'];
            $placeCode = (string) ($place['code'] ?? '');

            if ($placeCode === '') {
                throw new RuntimeException('Meteo.lt did not return a usable place code.');
            }

            $forecastMeta = $this->rememberWithMeta(
                "dev-meteo-forecast:{$placeCode}",
                now()->addMinutes(20),
                fn (): array => $this->meteoLtClient->getLongTermForecast($placeCode)
            );

            $forecast = $forecastMeta['value'];
            $entries = collect($forecast['forecastTimestamps'] ?? [])
                ->filter(fn (mixed $entry) => is_array($entry))
                ->values();

            $normalized = $this->normalizeDebugEntries($entries);
            $firstDay = $normalized['daily'][0] ?? null;

            return [
                'source' => 'live_meteo_lt',
                'request' => [
                    'place' => [
                        'source' => $placeMeta['hit'] ? 'cache' : 'live_api',
                        'cache_key' => $placeMeta['key'],
                    ],
                    'forecast' => [
                        'source' => $forecastMeta['hit'] ? 'cache' : 'live_api',
                        'cache_key' => $forecastMeta['key'],
                    ],
                ],
                'resolved_place' => $place,
                'raw_place_lookup' => $place,
                'raw_forecast' => $forecast,
                'normalized' => [
                    'current' => $firstDay,
                    'daily' => $normalized['daily'],
                    'timestamps' => $normalized['timestamps'],
                ],
            ];
        } catch (Throwable $exception) {
            Log::warning('Failed to fetch Meteo.lt forecast for debug endpoint.', [
                'city' => $city,
                'error' => $exception->getMessage(),
            ]);

            $fallbackRows = WeatherForecast::query()
                ->where('city', $city)
                ->orderBy('date')
                ->orderByDesc('id')
                ->limit(7)
                ->get()
                ->values();

            if ($fallbackRows->isEmpty()) {
                throw $exception;
            }

            $normalizedRows = $fallbackRows->map(function (WeatherForecast $forecast): array {
                return [
                    'date' => optional($forecast->date)->toDateString() ?? (string) $forecast->date,
                    'forecast_time_utc' => optional($forecast->date)->toDateString() ?? (string) $forecast->date,
                    'temperature' => round((float) $forecast->temperature, 2),
                    'temp_min' => round((float) ($forecast->temp_min ?? $forecast->temperature), 2),
                    'temp_max' => round((float) ($forecast->temp_max ?? $forecast->temperature), 2),
                    'precipitation_mm' => round((float) $forecast->precipitation, 2),
                    'humidity' => round((float) $forecast->humidity, 2),
                    'wind_kmh' => round((float) ($forecast->wind_kmh ?? 0), 2),
                    'source' => (string) ($forecast->source ?? 'stored_weather_forecasts'),
                    'source_date' => optional($forecast->source_date)->toDateString(),
                    'source_city' => $forecast->source_city,
                ];
            })->values()->all();

            return [
                'source' => 'stored_weather_forecasts',
                'request' => [
                    'place' => [
                        'source' => $placeMeta && $placeMeta['hit'] ? 'cache' : 'not_available',
                        'cache_key' => $placeMeta['key'] ?? null,
                    ],
                    'forecast' => [
                        'source' => $forecastMeta && $forecastMeta['hit'] ? 'cache' : 'fallback',
                        'cache_key' => $forecastMeta['key'] ?? null,
                    ],
                ],
                'resolved_place' => $placeMeta['value'] ?? null,
                'raw_place_lookup' => $placeMeta['value'] ?? null,
                'raw_forecast' => null,
                'normalized' => [
                    'current' => $normalizedRows[0] ?? null,
                    'daily' => $normalizedRows,
                    'timestamps' => [],
                ],
            ];
        }
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

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function normalizeDebugEntries(Collection $entries): array
    {
        $timestampRows = $entries
            ->map(function (array $entry): array {
                $temp = (float) data_get($entry, 'airTemperature', 0);

                return [
                    'forecast_time_utc' => (string) data_get($entry, 'forecastTimeUtc', ''),
                    'temperature' => round($temp, 2),
                    'temp_min' => round($temp, 2),
                    'temp_max' => round($temp, 2),
                    'precipitation_mm' => round((float) data_get($entry, 'totalPrecipitation', 0), 2),
                    'humidity' => round((float) data_get($entry, 'relativeHumidity', 0), 2),
                    'wind_kmh' => round((float) data_get($entry, 'windSpeed', 0) * 3.6, 2),
                    'condition_code' => data_get($entry, 'conditionCode'),
                    'source' => 'live_meteo_lt',
                ];
            })
            ->take(8)
            ->values()
            ->all();

        $dailyRows = $entries
            ->groupBy(function (array $entry): string {
                return Carbon::parse((string) $entry['forecastTimeUtc'], 'UTC')->toDateString();
            })
            ->map(function (Collection $dayEntries, string $dateKey): array {
                $aggregated = $this->aggregateDay($dayEntries);
                $firstTimestamp = (string) data_get($dayEntries->first(), 'forecastTimeUtc', $dateKey);

                return array_merge($aggregated->toArray(), [
                    'date' => $dateKey,
                    'forecast_time_utc' => $firstTimestamp,
                    'temperature' => $aggregated->averageTemperature(),
                    'source' => 'live_meteo_lt',
                ]);
            })
            ->values()
            ->all();

        return [
            'timestamps' => $timestampRows,
            'daily' => $dailyRows,
        ];
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

    /**
     * @template TValue
     *
     * @param  \Closure():TValue  $resolver
     * @return array{key:string,hit:bool,value:TValue}
     */
    private function rememberWithMeta(string $key, mixed $ttl, \Closure $resolver): array
    {
        $hit = Cache::has($key);
        $value = Cache::remember($key, $ttl, $resolver);

        return [
            'key' => $key,
            'hit' => $hit,
            'value' => $value,
        ];
    }
}
