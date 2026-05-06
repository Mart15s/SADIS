<?php

namespace App\Services\Integrations;

use App\Exceptions\UpstreamServiceException;
use App\Support\PlantCareName;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PerenualService
{
    private const DEFAULT_SEARCH_RESULT_LIMIT = 3;

    private const MAX_SEARCH_RESULT_LIMIT = 9;

    private const CARE_GUIDE_ENRICHMENT_TYPES = [
        'watering',
        'sunlight',
        'pruning',
        'fertilizing',
        'soil',
        'pests',
    ];

    /**
     * @return array<int, array{id:int,name:string,scientific_name:?string,other_names:array<int,string>,cycle:?string,watering:?string,sunlight:array<int,string>,image:?string,match_score:int}>
     */
    public function searchPlants(string $query, ?int $limit = null): array
    {
        $query = trim($query);
        $limit = $this->resolveSearchLimit($limit);

        if ($query === '') {
            return [
                'data' => [],
                'meta' => [
                    'limit' => $limit,
                    'count' => 0,
                    'has_more' => false,
                    'next_limit' => null,
                ],
            ];
        }

        $response = $this->rememberWithMeta(
            sprintf('perenual-search:%s:%d', Str::lower($query), $limit),
            now()->addHour(),
            fn (): array => $this->searchSpeciesResponse($query, $limit)
        )['value'];

        $results = collect($response['data'] ?? [])
            ->filter(fn (mixed $item) => is_array($item) && isset($item['id'], $item['common_name']))
            ->map(fn (array $item) => [
                'item' => $item,
                'score' => $this->scoreSpeciesMatch($query, $item),
            ])
            ->filter(fn (array $ranked) => $ranked['score'] > 0)
            ->sortByDesc(fn (array $ranked) => ($ranked['score'] * 100) + $this->speciesTieBreakerScore($ranked['item']))
            ->map(function (array $ranked): array {
                $item = $ranked['item'];

                return [
                    'id' => (int) $item['id'],
                    'name' => (string) $item['common_name'],
                    'scientific_name' => $this->sanitizeCatalogScientificName($item['scientific_name'] ?? null),
                    'other_names' => $this->sanitizeCatalogStringList($item['other_name'] ?? []),
                    'cycle' => $this->sanitizeCatalogString($item['cycle'] ?? null),
                    'watering' => $this->sanitizeCatalogString($item['watering'] ?? null),
                    'sunlight' => $this->sanitizeCatalogStringList($item['sunlight'] ?? []),
                    'image' => $this->resolveSearchImage($item),
                    'match_score' => $ranked['score'],
                ];
            })
            ->take($limit)
            ->values()
            ->all();

        $hasMore = (bool) ($response['meta']['has_more'] ?? false) && $limit < self::MAX_SEARCH_RESULT_LIMIT;

        return [
            'data' => $results,
            'meta' => [
                'limit' => $limit,
                'count' => count($results),
                'has_more' => $hasMore,
                'next_limit' => $hasMore ? min($limit + self::DEFAULT_SEARCH_RESULT_LIMIT, self::MAX_SEARCH_RESULT_LIMIT) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function debugSearchPlants(string $query, int $limit = 5): array
    {
        $query = trim($query);

        if ($query === '') {
            return [
                'query' => $query,
                'results' => [],
                'raw_response' => [],
                'request' => [
                    'source' => 'cache',
                    'cache_key' => 'perenual-search:',
                    'result_count' => 0,
                ],
            ];
        }

        $cached = $this->rememberWithMeta(
            sprintf('perenual-search:%s:%d', Str::lower($query), self::DEFAULT_SEARCH_RESULT_LIMIT),
            now()->addHour(),
            fn (): array => $this->searchSpeciesResponse($query, self::DEFAULT_SEARCH_RESULT_LIMIT)
        );

        $results = collect($cached['value']['data'] ?? [])
            ->filter(fn (mixed $item) => is_array($item) && isset($item['id'], $item['common_name']))
            ->map(fn (array $item) => [
                'item' => $item,
                'score' => $this->scoreSpeciesMatch($query, $item),
            ])
            ->filter(fn (array $ranked) => $ranked['score'] > 0)
            ->sortByDesc(fn (array $ranked) => ($ranked['score'] * 100) + $this->speciesTieBreakerScore($ranked['item']))
            ->map(function (array $ranked): array {
                $item = $ranked['item'];

                return [
                    'id' => (int) $item['id'],
                    'name' => (string) $item['common_name'],
                    'scientific_name' => $this->sanitizeCatalogScientificName($item['scientific_name'] ?? null),
                    'other_names' => $this->sanitizeCatalogStringList($item['other_name'] ?? []),
                    'family' => $this->sanitizeCatalogString($item['family'] ?? null),
                    'cycle' => $this->sanitizeCatalogString($item['cycle'] ?? null),
                    'watering' => $this->sanitizeCatalogString($item['watering'] ?? null),
                    'sunlight' => $this->sanitizeCatalogStringList($item['sunlight'] ?? []),
                    'image' => $this->resolveSearchImage($item),
                    'match_score' => $ranked['score'],
                ];
            })
            ->take(max(1, min($limit, self::DEFAULT_SEARCH_RESULT_LIMIT)))
            ->values()
            ->all();

        return [
            'query' => $query,
            'results' => $results,
            'raw_response' => $cached['value']['data'] ?? [],
            'request' => [
                'source' => $cached['hit'] ? 'cache' : 'live_api',
                'cache_key' => $cached['key'],
                'result_count' => count($cached['value']['data'] ?? []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchSpeciesSeed(string $plantName, ?int $speciesId = null): array
    {
        $species = null;
        $matchedSpeciesId = $speciesId;
        $matchedBy = $speciesId !== null ? 'explicit_species_id' : 'unresolved';

        if ($matchedSpeciesId === null) {
            $results = $this->rememberWithMeta(
                sprintf('perenual-search:%s:%d', Str::lower(trim($plantName)), self::DEFAULT_SEARCH_RESULT_LIMIT),
                now()->addHour(),
                fn (): array => $this->searchSpeciesResponse($plantName, self::DEFAULT_SEARCH_RESULT_LIMIT)
            )['value'];

            $species = $this->selectBestSpeciesCandidate($plantName, $results['data'] ?? []);

            if (! $species) {
                throw new RuntimeException('No suitable Perenual species match was found.');
            }

            $matchedSpeciesId = (int) $species['id'];
            $matchedBy = $this->scoreSpeciesMatch($plantName, $species) >= 110
                ? 'search_exact'
                : 'search_ranked';
        }

        $details = $this->rememberWithMeta(
            $this->detailsCacheKey($matchedSpeciesId),
            now()->addDay(),
            fn (): array => $this->fetchSpeciesDetailsById($matchedSpeciesId)
        )['value'];
        $careGuidePayloads = $this->fetchEnrichedCareGuidePayloads($matchedSpeciesId);

        return [
            'matched_species_id' => $matchedSpeciesId,
            'matched_by' => $matchedBy,
            'source_quality' => 'partial',
            'search_match' => $species,
            'details' => $details,
            'care_guides' => $this->normalizeCareGuidesFromPayloads($careGuidePayloads),
            'care_guides_raw' => $careGuidePayloads,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function debugLoadSpecies(int $speciesId, ?string $careGuideType = null): array
    {
        $details = $this->rememberWithMeta(
            $this->detailsCacheKey($speciesId),
            now()->addDay(),
            fn (): array => $this->fetchSpeciesDetailsById($speciesId)
        );

        $careGuide = $this->rememberWithMeta(
            $this->careGuideCacheKey($speciesId, $careGuideType),
            now()->addDay(),
            fn (): array => $this->fetchSpeciesCareGuideById($speciesId, $careGuideType)
        );

        return [
            'details' => [
                'payload' => $details['value'],
                'request' => [
                    'source' => $details['hit'] ? 'cache' : 'live_api',
                    'cache_key' => $details['key'],
                ],
            ],
            'care_guide' => [
                'payload' => $careGuide['value'],
                'request' => [
                    'source' => $careGuide['hit'] ? 'cache' : 'live_api',
                    'cache_key' => $careGuide['key'],
                    'type' => $careGuideType ?: 'all',
                ],
            ],
        ];
    }

    private function ensureSuccessfulResponse(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $retryAfter = is_numeric($response->header('Retry-After'))
            ? (int) $response->header('Retry-After')
            : null;

        throw new UpstreamServiceException(
            message: "Perenual {$context} request failed with status {$response->status()}.",
            provider: 'perenual',
            context: $context,
            status: $response->status(),
            retryAfterSeconds: $retryAfter,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchSpeciesResponse(string $query, int $perPage): array
    {
        $apiKey = config('services.perenual.key');

        if (! $apiKey) {
            throw new RuntimeException('Perenual API key is not configured.');
        }

        $baseUrl = rtrim((string) config('services.perenual.base_url'), '/');

        $response = Http::timeout(10)
            ->retry(2, 250, throw: false)
            ->get("{$baseUrl}/species-list", [
                'key' => $apiKey,
                'q' => $query,
                'per_page' => $perPage,
            ]);

        $this->ensureSuccessfulResponse($response, 'species-list');

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Malformed Perenual species-list response.');
        }

        $data = collect((array) ($payload['data'] ?? []))
            ->filter(fn (mixed $item) => is_array($item) && isset($item['id'], $item['common_name']))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'has_more' => $this->speciesResponseHasMore($payload),
            ],
        ];
    }

    private function resolveSearchLimit(?int $limit): int
    {
        if ($limit === null) {
            return self::DEFAULT_SEARCH_RESULT_LIMIT;
        }

        return max(1, min($limit, self::MAX_SEARCH_RESULT_LIMIT));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function speciesResponseHasMore(array $payload): bool
    {
        $currentPage = isset($payload['current_page']) && is_numeric($payload['current_page'])
            ? (int) $payload['current_page']
            : null;
        $lastPage = isset($payload['last_page']) && is_numeric($payload['last_page'])
            ? (int) $payload['last_page']
            : null;

        if ($currentPage !== null && $lastPage !== null) {
            return $currentPage < $lastPage;
        }

        $to = isset($payload['to']) && is_numeric($payload['to'])
            ? (int) $payload['to']
            : null;
        $total = isset($payload['total']) && is_numeric($payload['total'])
            ? (int) $payload['total']
            : null;

        if ($to !== null && $total !== null) {
            return $to < $total;
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>|null
     */
    private function selectBestSpeciesCandidate(string $plantName, array $results): ?array
    {
        $ranked = Collection::make($results)
            ->map(fn (array $result) => [
                'score' => $this->scoreSpeciesMatch($plantName, $result),
                'result' => $result,
            ])
            ->sortByDesc(fn (array $ranked) => ($ranked['score'] * 100) + $this->speciesTieBreakerScore($ranked['result']))
            ->first();

        if (! $ranked || ($ranked['score'] ?? 0) <= 0) {
            return null;
        }

        return $ranked['result'];
    }

    /**
     * @param  array<string, mixed>  $species
     */
    private function scoreSpeciesMatch(string $plantName, array $species): int
    {
        $input = PlantCareName::normalize($plantName);

        if (! $input) {
            return 0;
        }

        $candidateNames = PlantCareName::normalizedList(array_merge(
            [$species['common_name'] ?? null],
            is_array($species['scientific_name'] ?? null) ? $species['scientific_name'] : [$species['scientific_name'] ?? null],
            is_array($species['other_name'] ?? null) ? $species['other_name'] : [$species['other_name'] ?? null],
        ));

        foreach ($candidateNames as $index => $candidate) {
            $exactScore = $index === 0 ? 120 : 110;
            $prefixScore = $index === 0 ? 95 : 88;
            $containsScore = $index === 0 ? 75 : 68;

            if ($candidate === $input) {
                return $exactScore;
            }

            if (Str::startsWith($candidate, $input) || Str::startsWith($input, $candidate)) {
                return $prefixScore;
            }

            if (Str::contains($candidate, $input) || Str::contains($input, $candidate)) {
                return $containsScore;
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $species
     */
    private function speciesTieBreakerScore(array $species): int
    {
        $score = 0;
        $scientificName = $this->sanitizeCatalogScientificName($species['scientific_name'] ?? null);

        if ($scientificName) {
            $wordCount = count(array_filter(explode(' ', $scientificName)));
            $score += max(0, 4 - max(0, $wordCount - 2));

            if (! Str::contains($scientificName, ["'", '"'])) {
                $score += 2;
            }
        }

        if ($this->resolveSearchImage($species)) {
            $score += 1;
        }

        return $score;
    }

    private function detailsCacheKey(int $speciesId): string
    {
        return "perenual-care-id:{$speciesId}";
    }

    private function careGuideCacheKey(int $speciesId, ?string $type = null): string
    {
        $suffix = trim((string) $type) !== '' ? Str::lower(trim((string) $type)) : 'all';

        return "perenual-care-guide:{$speciesId}:{$suffix}";
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSpeciesDetailsById(int $speciesId): array
    {
        $apiKey = config('services.perenual.key');

        if (! $apiKey) {
            throw new RuntimeException('Perenual API key is not configured.');
        }

        $baseUrl = rtrim((string) config('services.perenual.base_url'), '/');

        $detailsResponse = Http::timeout(10)
            ->retry(2, 250, throw: false)
            ->get("{$baseUrl}/species/details/{$speciesId}", [
                'key' => $apiKey,
            ]);

        $this->ensureSuccessfulResponse($detailsResponse, 'species-details');

        $details = $detailsResponse->json();

        if (! is_array($details)) {
            throw new RuntimeException('Malformed Perenual details response.');
        }

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSpeciesCareGuideById(int $speciesId, ?string $type = null): array
    {
        $apiKey = config('services.perenual.key');

        if (! $apiKey) {
            throw new RuntimeException('Perenual API key is not configured.');
        }

        $baseUrl = rtrim((string) config('services.perenual.base_url'), '/');
        $params = [
            'key' => $apiKey,
            'species_id' => $speciesId,
        ];

        if (trim((string) $type) !== '') {
            $params['type'] = trim((string) $type);
        }

        $response = Http::timeout(10)
            ->retry(2, 250, throw: false)
            ->get("{$baseUrl}/species-care-guide-list", $params);

        $this->ensureSuccessfulResponse($response, 'species-care-guide-list');

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Malformed Perenual care guide response.');
        }

        return $payload;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchEnrichedCareGuidePayloads(int $speciesId): array
    {
        $payloads = [
            'all' => $this->rememberWithMeta(
                $this->careGuideCacheKey($speciesId),
                now()->addDay(),
                fn (): array => $this->fetchSpeciesCareGuideById($speciesId)
            )['value'],
        ];

        if (count($this->normalizeCareGuidesFromPayloads($payloads)) >= 3) {
            return $payloads;
        }

        foreach (self::CARE_GUIDE_ENRICHMENT_TYPES as $type) {
            try {
                $payloads[$type] = $this->rememberWithMeta(
                    $this->careGuideCacheKey($speciesId, $type),
                    now()->addDay(),
                    fn (): array => $this->fetchSpeciesCareGuideById($speciesId, $type)
                )['value'];
            } catch (Throwable $exception) {
                Log::info('Skipping supplemental Perenual care guide payload.', [
                    'species_id' => $speciesId,
                    'type' => $type,
                    'error' => $exception->getMessage(),
                ]);

                if ($exception instanceof UpstreamServiceException && $exception->status === 429) {
                    break;
                }
            }
        }

        return $payloads;
    }

    /**
     * @template TValue
     *
     * @param  \Closure():TValue  $resolver
     * @return array{key:string,hit:bool,value:TValue}
     */
    private function rememberWithMeta(string $key, mixed $ttl, \Closure $resolver): array
    {
        $resolvedFromCache = true;
        $value = Cache::remember($key, $ttl, function () use ($resolver, &$resolvedFromCache): mixed {
            $resolvedFromCache = false;

            return $resolver();
        });

        return [
            'key' => $key,
            'hit' => $resolvedFromCache,
            'value' => $value,
        ];
    }

    private function sanitizeCatalogString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $normalized = Str::lower($text);

        if (Str::contains($normalized, [
            'upgrade plan',
            'upgrade plans',
            'subscription-api-pricing',
            "i'm sorry",
            'im sorry',
        ])) {
            Log::warning('Discarded placeholder text from Perenual catalog payload.', [
                'preview' => Str::limit($text, 80),
            ]);

            return null;
        }

        return $text;
    }

    /**
     * @return array<int, string>
     */
    private function sanitizeCatalogStringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->map(fn (mixed $entry) => $this->sanitizeCatalogString($entry))
            ->filter()
            ->values()
            ->all();
    }

    private function sanitizeCatalogScientificName(mixed $value): ?string
    {
        return $this->sanitizeCatalogStringList($value)[0] ?? null;
    }

    private function resolveSearchImage(array $item): ?string
    {
        $image = $item['default_image'] ?? null;

        if (! is_array($image)) {
            return null;
        }

        foreach (['regular_url', 'original_url', 'medium_url', 'small_url', 'thumbnail'] as $key) {
            $value = $image[$key] ?? null;

            if (is_string($value) && $value !== '' && ! Str::contains(Str::lower($value), 'upgrade_access')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function normalizeCareGuides(array $payload): array
    {
        return $this->normalizeCareGuidesFromPayloads(['all' => $payload]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloads
     * @return array<string, string>
     */
    private function normalizeCareGuidesFromPayloads(array $payloads): array
    {
        $guides = [];

        foreach ($payloads as $payload) {
            foreach ((array) ($payload['data'] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $section = $entry['section'] ?? null;

                if (is_array($section)) {
                    foreach ($section as $nested) {
                        $this->appendGuideSection($guides, $nested);
                    }

                    continue;
                }

                $this->appendGuideSection($guides, $entry);
            }
        }

        return collect($guides)
            ->map(fn (string $text) => trim(preg_replace('/\s+/', ' ', $text) ?? $text))
            ->filter(fn (string $text) => $text !== '')
            ->all();
    }

    /**
     * @param  array<string, string>  $guides
     */
    private function appendGuideSection(array &$guides, mixed $entry): void
    {
        if (! is_array($entry)) {
            return;
        }

        $type = $this->sanitizeCatalogString($entry['type'] ?? $entry['section'] ?? null);
        $description = $this->sanitizeCatalogString($entry['description'] ?? null);

        if (! $type || ! $description) {
            return;
        }

        $key = Str::lower($type);
        $guides[$key] = isset($guides[$key])
            ? "{$guides[$key]}\n\n{$description}"
            : $description;
    }
}
