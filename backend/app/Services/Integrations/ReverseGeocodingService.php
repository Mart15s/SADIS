<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ReverseGeocodingService
{
    public function resolveCity(float $latitude, float $longitude): ?array
    {
        $baseUrl = rtrim((string) config('services.nominatim.base_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('Reverse geocoding base URL is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->withUserAgent((string) config('services.nominatim.user_agent'))
                ->timeout(6)
                ->retry(1, 300, throw: false)
                ->get($baseUrl.'/reverse', [
                    'format' => 'jsonv2',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'addressdetails' => 1,
                    'zoom' => 10,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $city = $this->firstFilled($address, [
            'city',
            'town',
            'village',
            'municipality',
            'county',
            'state',
        ]);

        if ($city === null) {
            return null;
        }

        return [
            'city' => $city,
            'display_name' => is_string($payload['display_name'] ?? null) ? $payload['display_name'] : null,
            'provider' => 'nominatim',
        ];
    }

    private function firstFilled(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($values[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
