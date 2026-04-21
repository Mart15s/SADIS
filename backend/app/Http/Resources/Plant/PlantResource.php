<?php

namespace App\Http\Resources\Plant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plantType = $this->type?->value ?? $this->type;
        $condition = $this->condition?->value ?? $this->condition;
        $loadedPlantZone = $this->relationLoaded('plantZone') ? $this->plantZone : null;
        $loadedPlot = $this->relationLoaded('plot') ? $this->plot : null;
        $loadedCatalogPlant = $this->relationLoaded('catalogPlant') ? $this->catalogPlant : null;
        $loadedSharedPlantCare = $loadedCatalogPlant?->relationLoaded('plantCare') && $loadedCatalogPlant->plantCare
            ? $loadedCatalogPlant->plantCare
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'growing_time_days' => $this->growing_time_days,
            'recommended_temperature' => $this->recommended_temperature === null ? null : (float) $this->recommended_temperature,
            'recommended_humidity' => $this->recommended_humidity === null ? null : (float) $this->recommended_humidity,
            'plant_date' => $this->plant_date?->toDateString(),
            'disease' => (bool) $this->disease,
            'disease_notes' => $this->disease_notes,
            'rest_time_days' => $this->rest_time_days,
            'plant_size' => $this->plant_size === null ? null : (float) $this->plant_size,
            'photo_url' => $this->photo_url,
            'reusable' => (bool) $this->reusable,
            'type' => $plantType,
            'plant_type' => $plantType,
            'condition' => $condition,
            'plant_zone_id' => $this->plant_zone_id,
            'fk_plant_zone_id' => $this->fk_plant_zone_id,
            'fk_plot_id' => $this->fk_plot_id,
            'fk_catalog_plant_id' => $this->fk_catalog_plant_id,
            'has_plant_care' => (bool) $loadedSharedPlantCare,
            'plantZone' => $loadedPlantZone ? $loadedPlantZone->toArray() : null,
            'plant_zone' => $loadedPlantZone ? [
                'id' => $loadedPlantZone->id,
                'name' => $loadedPlantZone->name,
                'plot_id' => $loadedPlantZone->plot_id,
            ] : null,
            'plot' => $loadedPlot ? [
                'id' => $loadedPlot->id,
                'name' => $loadedPlot->name,
                'city' => $loadedPlot->city,
            ] : null,
            'catalogPlant' => $loadedCatalogPlant ? [
                'id' => $loadedCatalogPlant->id,
                'name' => $loadedCatalogPlant->name,
                'canonical_name' => $loadedCatalogPlant->canonical_name,
                'plant_type' => $loadedCatalogPlant->plant_type?->value ?? $loadedCatalogPlant->plant_type,
                'description' => $loadedCatalogPlant->description,
            ] : null,
            'catalog_plant' => $loadedCatalogPlant ? [
                'id' => $loadedCatalogPlant->id,
                'name' => $loadedCatalogPlant->name,
                'canonical_name' => $loadedCatalogPlant->canonical_name,
                'plant_type' => $loadedCatalogPlant->plant_type?->value ?? $loadedCatalogPlant->plant_type,
                'description' => $loadedCatalogPlant->description,
            ] : null,
            'sharedPlantCare' => $loadedSharedPlantCare ? $loadedSharedPlantCare->toArray() : null,
            'shared_plant_care' => $loadedSharedPlantCare ? $loadedSharedPlantCare->toArray() : null,
            'plantCare' => $loadedSharedPlantCare ? $loadedSharedPlantCare->toArray() : null,
            'plant_care' => $loadedSharedPlantCare ? $loadedSharedPlantCare->toArray() : null,
            'plant_care_summary' => $loadedSharedPlantCare ? [
                'id' => $loadedSharedPlantCare->id,
                'plant_name' => $loadedSharedPlantCare->plant_name,
                'canonical_name' => $loadedSharedPlantCare->canonical_name,
                'source_provider' => $loadedSharedPlantCare->source_provider,
                'source_quality' => $loadedSharedPlantCare->source_quality,
                'watering_interval_days' => $loadedSharedPlantCare->watering_interval_days,
                'fertilizing_interval_days' => $loadedSharedPlantCare->fertilizing_interval_days,
                'pest_check_interval_days' => $loadedSharedPlantCare->pest_check_interval_days,
            ] : null,
        ];
    }
}
