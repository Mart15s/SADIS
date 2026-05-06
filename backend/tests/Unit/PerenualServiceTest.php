<?php

namespace Tests\Unit;

use App\Services\Integrations\PerenualService;
use ReflectionMethod;
use Tests\TestCase;

class PerenualServiceTest extends TestCase
{
    public function test_score_species_match_considers_other_name_arrays(): void
    {
        $service = app(PerenualService::class);
        $method = new ReflectionMethod(PerenualService::class, 'scoreSpeciesMatch');
        $method->setAccessible(true);

        $score = $method->invoke($service, 'gherkin', [
            'common_name' => 'Cucumber',
            'scientific_name' => ['Cucumis sativus'],
            'other_name' => ['Gherkin'],
        ]);

        $this->assertGreaterThanOrEqual(110, $score);
    }
}
