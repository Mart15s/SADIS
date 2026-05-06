<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReverseGeocodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reverse_geocode_returns_city_from_coordinates(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([
                'display_name' => 'Vilnius, Lithuania',
                'address' => [
                    'city' => 'Vilnius',
                    'country' => 'Lithuania',
                ],
            ], 200),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/geocode/reverse?lat=54.6872&lng=25.2797')
            ->assertOk()
            ->assertJsonPath('data.city', 'Vilnius')
            ->assertJsonPath('data.provider', 'nominatim');
    }

    public function test_reverse_geocode_fails_softly_when_provider_has_no_city(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([], 503),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/geocode/reverse?lat=54.6872&lng=25.2797')
            ->assertOk()
            ->assertJsonPath('data.city', null);
    }
}
