<?php

namespace App\Http\Controllers\Api\Dev;

use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Models\Plant;
use App\Services\PlantCareService;
use App\Services\PerenualService;
use App\Services\PlantCareNormalizer;
use App\Services\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PlantCareDebugController extends Controller
{
    public function search(Request $request, PerenualService $perenualService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        try {
            return response()->json(
                $perenualService->debugSearchPlants($validated['q'], 5)
            );
        } catch (UpstreamServiceException $exception) {
            return $this->upstreamErrorResponse($exception, 'Perenual search failed');
        }
    }

    public function species(
        Request $request,
        int $speciesId,
        PerenualService $perenualService,
        PlantCareNormalizer $plantCareNormalizer,
        PlantCareService $plantCareService,
    ): JsonResponse {
        try {
            $careGuideType = $request->query('care_guide_type');
            $bundle = $perenualService->debugLoadSpecies($speciesId, is_string($careGuideType) ? $careGuideType : null);
            $details = $bundle['details']['payload'];
            $resolvedPlantName = trim((string) (($details['common_name'] ?? data_get($details, 'scientific_name.0')) ?? 'Debug plant'));
            $careGuidePayload = $bundle['care_guide']['payload'];
            $seed = [
                'matched_species_id' => $speciesId,
                'matched_by' => 'explicit_species_id',
                'source_quality' => 'partial',
                'details' => $details,
                'care_guides' => $this->normalizeCareGuides($careGuidePayload),
            ];

            $plant = new Plant([
                'name' => $resolvedPlantName,
            ]);

            $normalized = $plantCareNormalizer->normalizeWithTrace($plant, $seed);
            $resolution = $plantCareService->previewLinkedCareProfile($plant, $speciesId, $seed, $normalized);
            return response()->json([
                'selected_species' => [
                    'id' => $speciesId,
                    'common_name' => $details['common_name'] ?? null,
                    'scientific_name' => data_get($details, 'scientific_name.0'),
                    'family' => $details['family'] ?? null,
                    'image_url' => $normalized['normalized']['source_image_url'] ?? null,
                ],
                'plant_context' => [
                    'plant_name' => $resolvedPlantName,
                    'plant_type' => null,
                    'matched_by' => 'explicit_species_id',
                ],
                'details' => [
                    'request' => $bundle['details']['request'],
                    'raw_response' => $details,
                ],
                'care_guides' => [
                    'request' => $bundle['care_guide']['request'],
                    'raw_response' => $careGuidePayload,
                    'available_types' => $this->availableCareGuideTypes($careGuidePayload),
                ],
                'normalization' => [
                    'uses_current_pipeline' => true,
                    'notes' => [
                        'The current backend normalization path uses the selected species details payload shown below.',
                        'Care guide payloads are fetched automatically for the selected species, shown for debug inspection, and now contribute to production plant_care normalization when usable.',
                    ],
                    'normalized_candidate' => $normalized['normalized'],
                    'trace' => $normalized['trace'],
                    'metadata' => [
                        'canonical_name' => $normalized['normalized']['canonical_name'] ?? null,
                        'source_provider' => $normalized['normalized']['source_provider'] ?? null,
                        'source_quality' => $normalized['normalized']['source_quality'] ?? null,
                        'source_perenual_species_id' => $normalized['normalized']['source_perenual_species_id'] ?? null,
                        'source_common_name' => $normalized['normalized']['source_common_name'] ?? null,
                        'source_scientific_name' => $normalized['normalized']['source_scientific_name'] ?? null,
                        'source_family' => $normalized['normalized']['source_family'] ?? null,
                        'source_image_url' => $normalized['normalized']['source_image_url'] ?? null,
                    ],
                    'local_resolution' => $resolution,
                    'mapping' => $this->mappingRows($resolution['field_sources'] ?? []),
                    'warnings' => collect($normalized['trace'])
                        ->filter(fn (array $entry, string $field) => in_array(($entry['status'] ?? null), ['family_default', 'global_fallback'], true) && ! in_array($field, [
                            'canonical_name',
                            'source_provider',
                            'source_quality',
                            'source_perenual_species_id',
                            'source_common_name',
                            'source_scientific_name',
                            'source_family',
                            'source_image_url',
                        ], true))
                        ->map(fn (array $entry, string $field) => "Field [{$field}] not available from API - using derived/default value.")
                        ->values()
                        ->all(),
                ],
                'backend_debug_payload' => [
                    'seed' => $seed,
                    'normalized' => $normalized,
                    'local_resolution' => $resolution,
                ],
            ]);
        } catch (UpstreamServiceException $exception) {
            return $this->upstreamErrorResponse($exception, 'Perenual details failed');
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function weather(Request $request, WeatherService $weatherService): JsonResponse
    {
        $validated = $request->validate([
            'city' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        try {
            return response()->json(
                $weatherService->debugForecast($validated['city'])
            );
        } catch (UpstreamServiceException $exception) {
            return $this->upstreamErrorResponse($exception, 'Meteo.lt failed');
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldSources
     * @return array<int, array<string, mixed>>
     */
    private function mappingRows(array $fieldSources): array
    {
        return collect([
            'description',
            'conditions',
            'growing_duration_days',
            'flowering_duration_days',
            'germinating_duration_days',
            'mature_duration_days',
            'mature_duration_end_days',
            'mature_end_duration_days',
            'regenerating_duration_days',
            'reusable',
            'plant_type',
            'condition',
            'plant_name',
            'task_type',
            'watering_interval_days',
            'fertilizing_interval_days',
            'pest_check_interval_days',
            'rain_skip_threshold_mm',
            'frost_temp_threshold_c',
            'heat_extra_water_temp_c',
            'wind_protection_kmh',
            'canonical_name',
            'source_provider',
            'source_quality',
            'source_perenual_species_id',
            'source_common_name',
            'source_scientific_name',
            'source_family',
            'source_image_url',
        ])->map(function (string $field) use ($fieldSources): array {
            $entry = $fieldSources[$field] ?? [
                'value' => null,
                'source_kind' => 'fallback',
                'source_detail' => 'unknown',
            ];

            return [
                'field' => $field,
                'value' => $entry['value'] ?? null,
                'source_kind' => $entry['source_kind'] ?? 'fallback',
                'source_detail' => $this->presentSourceDetail((string) ($entry['source_detail'] ?? 'unknown')),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function availableCareGuideTypes(array $payload): array
    {
        return collect((array) ($payload['data'] ?? []))
            ->map(function (mixed $guide): ?string {
                if (! is_array($guide)) {
                    return null;
                }

                foreach (['section', 'type'] as $field) {
                    $value = $guide[$field] ?? null;

                    if (is_string($value) && trim($value) !== '') {
                        return trim($value);
                    }
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function presentSourceDetail(string $detail): string
    {
        return str_replace('api.', 'details.', $detail);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function normalizeCareGuides(array $payload): array
    {
        $guides = [];

        foreach ((array) ($payload['data'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sections = $entry['section'] ?? null;

            if (is_array($sections)) {
                foreach ($sections as $section) {
                    $this->appendGuideSection($guides, $section);
                }

                continue;
            }

            $this->appendGuideSection($guides, $entry);
        }

        return $guides;
    }

    /**
     * @param  array<string, string>  $guides
     */
    private function appendGuideSection(array &$guides, mixed $entry): void
    {
        if (! is_array($entry)) {
            return;
        }

        $type = isset($entry['type']) && is_string($entry['type']) ? trim(strtolower($entry['type'])) : null;
        $description = isset($entry['description']) && is_string($entry['description']) ? trim($entry['description']) : null;

        if (! $type || ! $description) {
            return;
        }

        $guides[$type] = isset($guides[$type])
            ? "{$guides[$type]}\n\n{$description}"
            : $description;
    }

    private function upstreamErrorResponse(UpstreamServiceException $exception, string $label): JsonResponse
    {
        $status = $exception->status >= 400 ? $exception->status : 502;

        return response()->json([
            'message' => "{$label}: {$exception->getMessage()}",
            'provider' => $exception->provider,
            'context' => $exception->context,
            'status' => $exception->status,
            'retry_after' => $exception->retryAfterSeconds,
        ], $status);
    }
}
