<?php

namespace Tests\Unit;

use App\Models\Plant;
use App\Services\Plant\PlantCareService;
use ReflectionMethod;
use Tests\TestCase;

class PlantCareServiceTest extends TestCase
{
    public function test_candidate_names_flattens_search_and_detail_alias_arrays(): void
    {
        $service = app(PlantCareService::class);
        $method = new ReflectionMethod(PlantCareService::class, 'candidateNames');
        $method->setAccessible(true);
        $plant = new Plant([
            'name' => 'Cucumber',
        ]);

        $names = $method->invoke($service, $plant, [
            'search_match' => [
                'scientific_name' => ['Cucumis sativus'],
                'other_name' => ['Gherkin'],
            ],
            'details' => [
                'common_name' => 'Garden Cucumber',
                'other_name' => ['Kirby cucumber'],
            ],
        ]);

        $this->assertContains('cucumber', $names);
        $this->assertContains('cucumis sativus', $names);
        $this->assertContains('gherkin', $names);
        $this->assertContains('kirby cucumber', $names);
    }
}
