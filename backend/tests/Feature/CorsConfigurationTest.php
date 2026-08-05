<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_configured_frontend_origin_can_preflight_bearer_requests(): void
    {
        config()->set('cors.allowed_origins', ['https://sadis.example']);
        config()->set('cors.supports_credentials', false);

        $response = $this->withHeaders([
            'Origin' => 'https://sadis.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/login');

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://sadis.example')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }
}
