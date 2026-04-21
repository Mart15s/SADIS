<?php

namespace App\Http\Resources\Calendar;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WeatherForecastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date?->toDateString(),
            'temperature' => (float) $this->temperature,
            'temp_min' => $this->temp_min === null ? null : (float) $this->temp_min,
            'temp_max' => $this->temp_max === null ? null : (float) $this->temp_max,
            'precipitation' => (float) $this->precipitation,
            'humidity' => (float) $this->humidity,
            'wind_kmh' => $this->wind_kmh === null ? null : (float) $this->wind_kmh,
            'condition_code' => $this->condition_code,
            'source' => $this->source,
            'source_date' => $this->source_date?->toDateString(),
            'source_city' => $this->source_city,
            'city' => $this->city,
            'is_seasonal_fallback' => (bool) $this->is_seasonal_fallback,
        ];
    }
}
