<?php

namespace App\Services;

use App\Models\GardenOwner;
use App\Models\Plot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlotSnapshotService
{
    public function capture(Plot $plot, string $action, ?GardenOwner $owner = null, array $metadata = []): void
    {
        $plot->loadMissing([
            'plantZones.plants',
            'plants',
        ]);

        DB::table('plot_snapshots')->insert([
            'plot_id' => $plot->id,
            'garden_owner_id' => $owner?->id,
            'action' => $action,
            'snapshot' => json_encode([
                'plot' => $plot->toArray(),
                'zones' => $plot->plantZones->toArray(),
                'plants' => $plot->plants->toArray(),
                'metadata' => $metadata,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForPlot(Plot $plot, int $limit = 50): Collection
    {
        return DB::table('plot_snapshots')
            ->where('plot_id', $plot->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (object $snapshot): array {
                $payload = json_decode((string) $snapshot->snapshot, true);

                return [
                    'id' => (int) $snapshot->id,
                    'plot_id' => (int) $snapshot->plot_id,
                    'garden_owner_id' => $snapshot->garden_owner_id === null ? null : (int) $snapshot->garden_owner_id,
                    'action' => $snapshot->action,
                    'created_at' => $snapshot->created_at,
                    'snapshot' => is_array($payload) ? $payload : [],
                ];
            });
    }
}
