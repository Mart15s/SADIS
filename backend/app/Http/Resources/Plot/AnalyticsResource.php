<?php

namespace App\Http\Resources\Plot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnalyticsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'plot' => $this['plot'],
            'selectedAnalysisTypes' => array_values($this['selectedAnalysisTypes'] ?? []),
            'sections' => (object) ($this['sections'] ?? []),
            'summary' => (object) ($this['summary'] ?? []),
            'generatedAt' => $this['generatedAt'] ?? null,
            'warnings' => array_values($this['warnings'] ?? []),
        ];
    }
}
