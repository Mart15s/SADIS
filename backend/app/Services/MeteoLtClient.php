<?php

namespace App\Services;

use App\Exceptions\UpstreamServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MeteoLtClient
{
    public function findPlaceByCity(string $city): array
    {
        $city = trim($city);

        if ($city === '') {
            throw new RuntimeException('City name is required for Meteo.lt place lookup.');
        }

        $places = $this->get('/places')->json();

        if (! is_array($places) || $places === []) {
            throw new RuntimeException('Meteo.lt places endpoint returned an empty or invalid response.');
        }

        $place = $this->resolvePlaceMatch($city, $places);

        if ($place === null) {
            throw new RuntimeException("Meteo.lt place not found for city [{$city}].");
        }

        return $place;
    }

    public function getLongTermForecast(string $placeCode): array
    {
        $payload = $this->get('/places/'.urlencode($placeCode).'/forecasts/long-term')->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Meteo.lt long-term forecast response is malformed.');
        }

        $timestamps = $payload['forecastTimestamps'] ?? null;

        if (! is_array($timestamps) || $timestamps === []) {
            throw new RuntimeException("Meteo.lt forecast is missing timestamps for place [{$placeCode}].");
        }

        return $payload;
    }

    private function get(string $path): Response
    {
        $baseUrl = rtrim((string) config('services.meteo_lt.base_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('Meteo.lt base URL is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->retry(2, 250, throw: false)
                ->get($baseUrl.$path);
        } catch (ConnectionException $exception) {
            throw new UpstreamServiceException(
                message: "Meteo.lt request failed for [{$path}] due to a network or timeout error: {$exception->getMessage()}",
                provider: 'meteo_lt',
                context: $path,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        $retryAfter = is_numeric($response->header('Retry-After'))
            ? (int) $response->header('Retry-After')
            : null;

        throw new UpstreamServiceException(
            message: "Meteo.lt request failed with status {$response->status()} for [{$path}].",
            provider: 'meteo_lt',
            context: $path,
            status: $response->status(),
            retryAfterSeconds: $retryAfter,
        );
    }

    private function resolvePlaceMatch(string $city, array $places): ?array
    {
        $normalizedCity = $this->normalizeValue($city);
        $exactMatches = [];
        $scoredMatches = [];

        foreach ($places as $place) {
            if (! is_array($place)) {
                continue;
            }

            $name = trim((string) ($place['name'] ?? ''));
            $code = trim((string) ($place['code'] ?? ''));

            if ($name === '' || $code === '') {
                continue;
            }

            $normalizedName = $this->normalizeValue($name);
            $normalizedCode = $this->normalizeValue($code);

            if ($name === $city || $normalizedName === $normalizedCity) {
                $exactMatches[] = $place;
                continue;
            }

            $score = 0;

            if ($normalizedCode === $normalizedCity) {
                $score = 90;
            } elseif (str_starts_with($normalizedName, $normalizedCity)) {
                $score = 70;
            } elseif (str_contains($normalizedName, $normalizedCity) || str_contains($normalizedCity, $normalizedName)) {
                $score = 50;
            } elseif (str_starts_with($normalizedCode, $normalizedCity) || str_contains($normalizedCode, $normalizedCity)) {
                $score = 30;
            }

            if ($score > 0) {
                $scoredMatches[] = [
                    'score' => $score,
                    'place' => $place,
                ];
            }
        }

        if ($exactMatches !== []) {
            return $this->pickMostAppropriatePlace($exactMatches);
        }

        if ($scoredMatches === []) {
            return null;
        }

        usort($scoredMatches, function (array $left, array $right): int {
            $scoreComparison = $right['score'] <=> $left['score'];

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return $this->comparePlaces($left['place'], $right['place']);
        });

        return $scoredMatches[0]['place'];
    }

    private function pickMostAppropriatePlace(array $places): array
    {
        usort($places, $this->comparePlaces(...));

        return $places[0];
    }

    private function comparePlaces(array $left, array $right): int
    {
        $leftCountry = strtoupper((string) ($left['countryCode'] ?? ''));
        $rightCountry = strtoupper((string) ($right['countryCode'] ?? ''));

        if ($leftCountry !== $rightCountry) {
            return $rightCountry === 'LT' ? 1 : -1;
        }

        $leftName = trim((string) ($left['name'] ?? ''));
        $rightName = trim((string) ($right['name'] ?? ''));
        $lengthComparison = mb_strlen($leftName) <=> mb_strlen($rightName);

        if ($lengthComparison !== 0) {
            return $lengthComparison;
        }

        return strcmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
    }

    private function normalizeValue(string $value): string
    {
        $ascii = Str::ascii($value);
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', strtolower($ascii));

        return trim((string) $normalized);
    }
}
