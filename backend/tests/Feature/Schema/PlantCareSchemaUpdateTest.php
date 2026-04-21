<?php

namespace Tests\Feature\Schema;

use App\Models\CatalogPlant;
use App\Models\PlantCare;
use App\Models\TaskCalendar;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlantCareSchemaUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_plant_care_schema_update_matches_phase_4b_requirements(): void
    {
        $this->assertFalse(Schema::hasColumn('plants', 'fk_plant_care_id'));
        $this->assertFalse(Schema::hasColumn('plants', 'plant_care_overrides'));
        $this->assertFalse(Schema::hasColumn('task_calendars', 'fk_plant_care_id'));

        foreach ([
            'watering_interval_days',
            'fertilizing_interval_days',
            'rain_skip_threshold_mm',
            'frost_temp_threshold_c',
            'heat_extra_water_temp_c',
            'wind_protection_kmh',
            'reusable',
            'canonical_name',
            'source_provider',
            'source_quality',
            'source_perenual_species_id',
            'source_common_name',
            'source_scientific_name',
            'source_family',
            'source_image_url',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('plant_care', $column));
        }

        $catalogPlant = new CatalogPlant();
        $plantCare = new PlantCare();

        $this->assertInstanceOf(BelongsTo::class, $catalogPlant->plantCare());
        $this->assertInstanceOf(HasManyThrough::class, $plantCare->plants());
        $this->assertInstanceOf(HasMany::class, $plantCare->catalogPlants());
        $this->assertFalse(method_exists(TaskCalendar::class, 'plantCare'));
    }
}
