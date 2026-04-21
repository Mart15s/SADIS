<?php

namespace App\Http\Resources\Community;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->relationLoaded('profile') ? $this->profile : null;
        $plot = $this->relationLoaded('plot') ? $this->plot : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'text' => $this->text,
            'share' => $this->share,
            'created_at' => $this->created_at?->toISOString(),
            'owner_name' => trim(implode(' ', array_filter([
                $profile?->name,
                $profile?->surname,
            ]))),
            'plot_id' => $this->plot_id ?? $plot?->id,
            'plot_name' => $plot?->name,
            'plot_preview' => $plot ? [
                'plot_id' => $plot->id,
                'plot_name' => $plot->name,
                'plot_size' => $plot->plot_size,
                'geometry' => $plot->geometry,
                'zones' => $plot->relationLoaded('plantZones')
                    ? $plot->plantZones
                        ->map(fn ($zone) => [
                            'id' => $zone->id,
                            'name' => $zone->name,
                            'geometry' => $zone->geometry,
                        ])
                        ->values()
                        ->all()
                    : [],
            ] : null,
        ];
    }
}
