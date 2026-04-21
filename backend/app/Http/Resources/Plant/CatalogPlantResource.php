<?php

namespace App\Http\Resources\Plant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogPlantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plantType = $this->plant_type?->value ?? $this->plant_type;
        $loadedPlantCare = $this->relationLoaded('plantCare') ? $this->plantCare : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'canonical_name' => $this->canonical_name,
            'plant_type' => $plantType,
            'description' => $this->description,
            'fk_plant_care_id' => $this->fk_plant_care_id,
            'has_plant_care' => (bool) ($this->fk_plant_care_id || $loadedPlantCare),
            'source_provider' => $this->source_provider,
            'source_quality' => $this->source_quality,
            'source_scientific_name' => $this->source_scientific_name,
            'source_family' => $this->source_family,
            'source_image_url' => $this->source_image_url,
            'metadata' => $this->metadata,
            'usage_count' => $this->whenCounted('plants', fn () => (int) $this->plants_count),
            'plantCare' => $loadedPlantCare ? $loadedPlantCare->toArray() : null,
            'plant_care' => $loadedPlantCare ? $loadedPlantCare->toArray() : null,
            'plant_care_summary' => $loadedPlantCare ? [
                'id' => $loadedPlantCare->id,
                'plant_name' => $loadedPlantCare->plant_name,
                'canonical_name' => $loadedPlantCare->canonical_name,
                'watering_interval_days' => $loadedPlantCare->watering_interval_days,
                'fertilizing_interval_days' => $loadedPlantCare->fertilizing_interval_days,
                'pest_check_interval_days' => $loadedPlantCare->pest_check_interval_days,
                'source_provider' => $loadedPlantCare->source_provider,
                'source_quality' => $loadedPlantCare->source_quality,
            ] : null,
        ];
    }
}
