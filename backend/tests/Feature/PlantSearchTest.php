<?php

namespace Tests\Feature;

use App\Models\GardenOwner;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlantSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_search_returns_normalized_perenual_results(): void
    {
        $user = User::factory()->create([
            'email' => 'search@example.com',
        ]);

        $profile = Profile::query()->create([
            'name' => 'Paieska',
            'surname' => 'Naudotojas',
        ]);

        GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        Sanctum::actingAs($user);

        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::fake([
            'https://perenual.test/api/species-list*' => Http::response([
                'data' => [
                    [
                        'id' => 987,
                        'common_name' => 'Tomato',
                        'sunlight' => ['full sun'],
                        'watering' => 'average',
                        'default_image' => [
                            'regular_url' => 'https://images.test/tomato.jpg',
                        ],
                        'ignored_field' => 'raw-payload-should-not-leak',
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/plants/search?q=tom')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 987,
                        'name' => 'Tomato',
                        'scientific_name' => null,
                        'other_names' => [],
                        'cycle' => null,
                        'match_score' => 95,
                        'sunlight' => ['full sun'],
                        'watering' => 'average',
                        'image' => 'https://images.test/tomato.jpg',
                    ],
                ],
                'meta' => [
                    'limit' => 3,
                    'count' => 1,
                    'has_more' => false,
                    'next_limit' => null,
                ],
            ]);
    }

    public function test_search_filters_premium_placeholder_fields(): void
    {
        $user = User::factory()->create([
            'email' => 'search-premium@example.com',
        ]);

        $profile = Profile::query()->create([
            'name' => 'Premium',
            'surname' => 'Filter',
        ]);

        GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        Sanctum::actingAs($user);

        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::fake([
            'https://perenual.test/api/species-list*' => Http::response([
                'data' => [
                    [
                        'id' => 5021,
                        'common_name' => 'Tomato',
                        'sunlight' => [
                            "Upgrade Plans To Premium/Supreme - https://perenual.com/subscription-api-pricing. I'm sorry",
                        ],
                        'watering' => "Upgrade Plans To Premium/Supreme - https://perenual.com/subscription-api-pricing. I'm sorry",
                        'default_image' => [
                            'regular_url' => 'https://s3.us-central-1.wasabisys.com/perenual/image/upgrade_access.jpg',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/plants/search?q=tom')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => 5021,
                        'name' => 'Tomato',
                        'scientific_name' => null,
                        'other_names' => [],
                        'cycle' => null,
                        'match_score' => 95,
                        'sunlight' => [],
                        'watering' => null,
                        'image' => null,
                    ],
                ],
                'meta' => [
                    'limit' => 3,
                    'count' => 1,
                    'has_more' => false,
                    'next_limit' => null,
                ],
            ]);
    }

    public function test_search_prefers_exact_match_over_broader_contains_results(): void
    {
        $user = User::factory()->create([
            'email' => 'search-order@example.com',
        ]);

        $profile = Profile::query()->create([
            'name' => 'Order',
            'surname' => 'Tester',
        ]);

        GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        Sanctum::actingAs($user);

        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::fake([
            'https://perenual.test/api/species-list*' => Http::response([
                'data' => [
                    [
                        'id' => 100,
                        'common_name' => 'Wall lettuce',
                        'sunlight' => ['partial shade'],
                        'watering' => 'average',
                    ],
                    [
                        'id' => 101,
                        'common_name' => 'Lettuce',
                        'sunlight' => ['full sun'],
                        'watering' => 'average',
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/plants/search?q=lettuce')
            ->assertOk()
            ->assertJsonPath('data.0.id', 101)
            ->assertJsonPath('data.0.name', 'Lettuce')
            ->assertJsonPath('data.0.match_score', 120)
            ->assertJsonPath('data.1.id', 100)
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.has_more', false);
    }
}
