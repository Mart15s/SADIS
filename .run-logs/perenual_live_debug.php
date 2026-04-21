<?php

require __DIR__ . '/../backend/vendor/autoload.php';

$app = require __DIR__ . '/../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Plant;
use App\Models\PlantCare;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

Cache::flush();

$base = 'http://127.0.0.1:8123/api';
$plantsToTest = ['Tomato', 'Basil', 'Mint', 'Lettuce', 'Cucumber'];
$timestamp = date('YmdHis');
$email = "perenual.debug.$timestamp@example.com";
$password = 'DebugPass123!';
$perenualKey = config('services.perenual.key');
$perenualBase = rtrim((string) config('services.perenual.base_url'), '/');

function request_json(string $method, string $url, ?string $token = null, ?array $payload = null): array
{
    $client = Http::timeout(30)->acceptJson();

    if ($token) {
        $client = $client->withToken($token);
    }

    $response = match (strtoupper($method)) {
        'GET' => $client->get($url, $payload ?? []),
        'POST' => $client->post($url, $payload ?? []),
        'PATCH' => $client->patch($url, $payload ?? []),
        'DELETE' => $client->delete($url, $payload ?? []),
        default => throw new RuntimeException("Unsupported method [$method]"),
    };

    return [
        'status' => $response->status(),
        'json' => $response->json(),
        'body' => $response->body(),
    ];
}

$register = request_json('POST', "$base/register", null, [
    'email' => $email,
    'password' => $password,
    'password_confirmation' => $password,
    'name' => 'Perenual',
    'surname' => 'Debug',
]);

if (($register['status'] ?? 500) >= 300) {
    throw new RuntimeException('Registration failed: ' . $register['body']);
}

$token = $register['json']['token'] ?? null;

if (! is_string($token) || $token === '') {
    throw new RuntimeException('Registration did not return a token.');
}

$plot = request_json('POST', "$base/plots", $token, [
    'name' => "Perenual Debug Plot $timestamp",
    'city' => 'Vilnius',
    'plot_size' => 12.5,
    'creation_date' => '2026-04-03',
    'description' => 'Temporary plot for Perenual integration debugging.',
    'share' => false,
]);

if (($plot['status'] ?? 500) >= 300) {
    throw new RuntimeException('Plot creation failed: ' . $plot['body']);
}

$plotId = $plot['json']['id'];

$zone = request_json('POST', "$base/plots/$plotId/plant-zones", $token, [
    'name' => 'Debug Zone',
    'zone_size' => 4.5,
    'soil_type' => 'clay',
    'rotation_stage' => 0,
    'last_planting_date' => null,
]);

if (($zone['status'] ?? 500) >= 300) {
    throw new RuntimeException('Zone creation failed: ' . $zone['body']);
}

$zoneId = $zone['json']['id'];

$results = [
    'meta' => [
        'generated_at' => date(DATE_ATOM),
        'api_base' => $base,
        'perenual_base' => $perenualBase,
        'perenual_key_redacted' => $perenualKey ? ('***' . substr($perenualKey, -4)) : null,
        'test_user_email' => $email,
        'plot_id' => $plotId,
        'zone_id' => $zoneId,
    ],
    'plants' => [],
];

foreach ($plantsToTest as $term) {
    Cache::forget('perenual-search:' . strtolower($term));
    Cache::forget('perenual-care:' . strtolower($term));

    $localSearch = request_json('GET', "$base/plants/search", $token, ['q' => $term]);

    $rawSearchResponse = Http::timeout(30)
        ->acceptJson()
        ->get("$perenualBase/species-list", [
            'key' => $perenualKey,
            'q' => $term,
            'per_page' => 10,
        ]);

    $rawSearchData = $rawSearchResponse->json();

    $backendSelectionResponse = Http::timeout(30)
        ->acceptJson()
        ->get("$perenualBase/species-list", [
            'key' => $perenualKey,
            'q' => $term,
            'per_page' => 1,
        ]);

    $backendSelectionData = $backendSelectionResponse->json();
    $selectedSpecies = $backendSelectionData['data'][0] ?? null;
    $selectedSpeciesId = is_array($selectedSpecies) ? ($selectedSpecies['id'] ?? null) : null;

    $detailsData = null;
    $detailsStatus = null;

    if ($selectedSpeciesId !== null) {
        $detailsResponse = Http::timeout(30)
            ->acceptJson()
            ->get("$perenualBase/species/details/$selectedSpeciesId", [
                'key' => $perenualKey,
            ]);

        $detailsStatus = $detailsResponse->status();
        $detailsData = $detailsResponse->json();
    }

    $selectedCatalogResult = null;

    if (($localSearch['status'] ?? 500) === 200 && is_array($localSearch['json']) && count($localSearch['json']) > 0) {
        $selectedCatalogResult = $localSearch['json'][0];
    }

    $nameForCreate = $selectedCatalogResult['name'] ?? $term;

    $createPlant = request_json('POST', "$base/plots/$plotId/plants", $token, [
        'name' => $nameForCreate,
        'plant_date' => '2026-04-03',
        'type' => 'vegetable',
        'condition' => 'planted',
        'disease' => false,
        'growing_time_days' => null,
        'recommended_temperature' => null,
        'recommended_humidity' => null,
        'rest_time_days' => null,
        'plant_size' => null,
        'fk_plant_zone_id' => $zoneId,
        'fk_plant_care_id' => null,
        'from_catalog' => true,
        'perenual_species_id' => $selectedCatalogResult['id'] ?? null,
    ]);

    $createdPlantId = $createPlant['json']['id'] ?? null;
    $loadedPlant = null;

    if ($createdPlantId) {
        $loadedPlant = request_json('GET', "$base/plots/$plotId/plants/$createdPlantId", $token);
    }

    $dbPlant = null;
    $dbCare = null;

    if ($createdPlantId) {
        $plantModel = Plant::query()->with('plantCare')->find($createdPlantId);

        if ($plantModel) {
            $dbPlant = [
                'id' => $plantModel->id,
                'name' => $plantModel->name,
                'fk_plant_care_id' => $plantModel->fk_plant_care_id,
                'type' => $plantModel->type?->value,
                'condition' => $plantModel->condition?->value,
                'reusable' => $plantModel->reusable,
            ];

            if ($plantModel->fk_plant_care_id) {
                $careModel = PlantCare::query()->find($plantModel->fk_plant_care_id);

                if ($careModel) {
                    $dbCare = $careModel->toArray();
                }
            }
        }
    }

    $results['plants'][$term] = [
        'local_search_status' => $localSearch['status'],
        'local_search_results' => $localSearch['json'],
        'local_selected_catalog_result' => $selectedCatalogResult,
        'raw_search_status' => $rawSearchResponse->status(),
        'raw_search_response' => $rawSearchData,
        'backend_selection_search_status' => $backendSelectionResponse->status(),
        'backend_selection_search_response' => $backendSelectionData,
        'backend_selected_species' => $selectedSpecies,
        'backend_selected_species_id' => $selectedSpeciesId,
        'raw_details_status' => $detailsStatus,
        'raw_details_response' => $detailsData,
        'create_plant_status' => $createPlant['status'],
        'create_plant_response' => $createPlant['json'],
        'loaded_plant_status' => $loadedPlant['status'] ?? null,
        'loaded_plant_response' => $loadedPlant['json'] ?? null,
        'db_plant' => $dbPlant,
        'db_care' => $dbCare,
    ];
}

$outputPath = __DIR__ . '/perenual-live-debug.json';
file_put_contents($outputPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo $outputPath . PHP_EOL;
