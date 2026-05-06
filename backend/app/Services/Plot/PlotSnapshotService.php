<?php

namespace App\Services\Plot;

use App\Models\GardenOwner;
use App\Models\Plot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlotSnapshotService
{
    private const HISTORY_ACTIONS = [
        'plot_created',
        'plot_updated',
        'plot_saved',
        'rotation_recorded',
    ];

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

    public function captureCommittedVersion(Plot $plot, ?GardenOwner $owner = null, array $metadata = []): void
    {
        $this->capture($plot, 'plot_saved', $owner, $metadata);
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

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listHistoryForPlot(Plot $plot, int $limit = 50): Collection
    {
        return $this->listForPlot($plot, $limit * 4)
            ->filter(fn (array $snapshot): bool => in_array($snapshot['action'], self::HISTORY_ACTIONS, true))
            ->map(function (array $snapshot): array {
                $payload = $snapshot['snapshot'];
                $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
                $zoneCount = count($payload['zones'] ?? []);
                $plantCount = count($payload['plants'] ?? []);
                $presentation = $this->presentAction($snapshot['action'], $metadata);

                return array_merge($snapshot, [
                    'label' => $presentation['label'],
                    'summary' => $presentation['summary'],
                    'zone_count' => $zoneCount,
                    'plant_count' => $plantCount,
                ]);
            })
            ->values()
            ->take($limit);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{label: string, summary: string}
     */
    private function presentAction(string $action, array $metadata): array
    {
        if ($action === 'plot_saved') {
            return [
                'label' => (string) ($metadata['label'] ?? 'Saved plot version'),
                'summary' => (string) ($metadata['summary'] ?? 'Committed plot workspace changes.'),
            ];
        }

        if ($action === 'rotation_recorded') {
            return [
                'label' => 'Saved rotation plan',
                'summary' => 'Rotation planning changes were confirmed and recorded.',
            ];
        }

        if ($action === 'plot_updated') {
            return [
                'label' => 'Saved plot details',
                'summary' => 'Plot metadata was updated and saved.',
            ];
        }

        return [
            'label' => 'Created plot version',
            'summary' => 'Initial plot version was created.',
        ];
    }
}
