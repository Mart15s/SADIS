<?php

namespace App\ValueObjects;

final readonly class WeatherData
{
    public function __construct(
        public float $tempMin,
        public float $tempMax,
        public float $precipitationMm,
        public float $humidity,
        public float $windKmh,
        public ?string $conditionCode = null,
        public bool $isSeasonalFallback = false,
        public string $source = 'api',
        public ?string $sourceDate = null,
        public ?string $sourceCity = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            tempMin: (float) ($data['temp_min'] ?? 0),
            tempMax: (float) ($data['temp_max'] ?? 0),
            precipitationMm: (float) ($data['precipitation_mm'] ?? 0),
            humidity: (float) ($data['humidity'] ?? 0),
            windKmh: (float) ($data['wind_kmh'] ?? 0),
            conditionCode: $data['condition_code'] ?? null,
            isSeasonalFallback: (bool) ($data['is_seasonal_fallback'] ?? false),
            source: (string) ($data['source'] ?? 'api'),
            sourceDate: $data['source_date'] ?? null,
            sourceCity: $data['source_city'] ?? null,
        );
    }

    public function averageTemperature(): float
    {
        return round(($this->tempMin + $this->tempMax) / 2, 2);
    }

    public function toArray(): array
    {
        return [
            'temp_min' => round($this->tempMin, 2),
            'temp_max' => round($this->tempMax, 2),
            'precipitation_mm' => round($this->precipitationMm, 2),
            'humidity' => round($this->humidity, 2),
            'wind_kmh' => round($this->windKmh, 2),
            'condition_code' => $this->conditionCode,
            'is_seasonal_fallback' => $this->isSeasonalFallback,
            'source' => $this->source,
            'source_date' => $this->sourceDate,
            'source_city' => $this->sourceCity,
        ];
    }

    public function isSnowCondition(): bool
    {
        $code = mb_strtolower((string) $this->conditionCode);

        return str_contains($code, 'snow')
            || str_contains($code, 'sleet')
            || str_contains($code, 'hail');
    }
}
