<?php

namespace App\Services;

use App\Models\GardenOwner;
use App\Models\Plot;
use App\Models\RotationHistory;
use App\Support\NormalizedGeometry;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;

class PdfExportService
{
    private const DEFAULT_PLOT_POINTS = [
        ['x' => 0.06, 'y' => 0.08],
        ['x' => 0.94, 'y' => 0.08],
        ['x' => 0.94, 'y' => 0.92],
        ['x' => 0.06, 'y' => 0.92],
    ];

    private const ZONE_COLORS = [
        ['fill' => '#9cb98c', 'stroke' => '#47633b'],
        ['fill' => '#cfb46c', 'stroke' => '#9b6b22'],
        ['fill' => '#c98c7c', 'stroke' => '#9a4c39'],
        ['fill' => '#8ab4aa', 'stroke' => '#2f6f68'],
        ['fill' => '#ad96c8', 'stroke' => '#64488f'],
        ['fill' => '#b7a18c', 'stroke' => '#71533a'],
    ];

    public function __construct(
        private readonly AnalyticsService $analyticsService,
    ) {
    }

    public function exportPlotReport(Plot $plot, GardenOwner $owner): Response
    {
        $html = $this->renderPlotReportHtml($plot, $owner);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdfOutput = $dompdf->output();

        $filename = 'plot-report.pdf';

        return response($pdfOutput, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdfOutput),
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function renderPlotReportHtml(Plot $plot, GardenOwner $owner): string
    {
        $plot = $this->loadPlotReportData($plot);
        $analytics = $this->analyticsService->analyzePlot($plot, $owner);
        $taskMetrics = $this->analyticsService->getTaskMetrics($plot);
        $recentCalendars = $plot->taskCalendars->take(3)->values();
        $recentTasks = $plot->taskCalendars
            ->flatMap(fn ($calendar) => $calendar->tasks)
            ->sortBy([['date', 'desc'], ['id', 'desc']])
            ->take(15)
            ->values();
        $recentRotationHistory = RotationHistory::query()
            ->where(function ($query) use ($plot) {
                $query
                    ->where('fk_plot_id', $plot->id)
                    ->orWhere('fk_plot_via_zone', $plot->id);
            })
            ->with([
                'plant:id,name',
                'plantZone:id,name,fk_plot_id',
            ])
            ->orderByDesc('to_date')
            ->orderByDesc('from_date')
            ->get()
            ->sortByDesc(fn ($rotation) => $rotation->to_date?->timestamp ?? $rotation->from_date?->timestamp ?? 0)
            ->take(10)
            ->values();
        $recentConditionHistory = $plot->plants
            ->flatMap(function ($plant) {
                return $plant->conditionHistory->map(function ($history) use ($plant) {
                    $history->setRelation('plant', $plant);

                    return $history;
                });
            })
            ->sortByDesc(fn ($history) => $history->measured_at?->timestamp ?? 0)
            ->take(10)
            ->values();

        return view('pdf.plot-report', [
            'plot' => $plot,
            'analytics' => $analytics,
            'task_metrics' => $taskMetrics,
            'recent_calendars' => $recentCalendars,
            'recent_tasks' => $recentTasks,
            'recent_rotation_history' => $recentRotationHistory,
            'recent_condition_history' => $recentConditionHistory,
            'generated_at' => now(),
            'plan_preview' => $this->buildPlanPreview($plot),
        ])->render();
    }

    private function loadPlotReportData(Plot $plot): Plot
    {
        return $plot->load([
            'plantZones' => fn ($query) => $query
                ->withCount('plants')
                ->orderBy('name'),
            'plants' => fn ($query) => $query
                ->with([
                    'plantZone:id,name,fk_plot_id',
                    'catalogPlant.plantCare:id,plant_name,watering_interval_days,fertilizing_interval_days,pest_check_interval_days',
                    'conditionHistory' => fn ($historyQuery) => $historyQuery->orderByDesc('measured_at'),
                ])
                ->orderBy('name'),
            'taskCalendars' => fn ($query) => $query
                ->with([
                    'tasks' => fn ($taskQuery) => $taskQuery
                        ->with([
                            'plant:id,name,fk_plant_zone_id',
                            'plant.plantZone:id,name,fk_plot_id',
                        ])
                        ->orderByDesc('date')
                        ->orderByDesc('id'),
                ])
                ->orderByDesc('creation_date'),
        ]);
    }

    private function buildPlanPreview(Plot $plot): array
    {
        $plotGeometry = NormalizedGeometry::isValid($plot->geometry)
            ? $plot->geometry
            : ['points' => self::DEFAULT_PLOT_POINTS];

        return [
            'source' => NormalizedGeometry::isValid($plot->geometry) ? 'geometry' : 'fallback',
            'plot' => [
                'points' => $this->toSvgPoints($plotGeometry['points']),
            ],
            'zones' => $this->buildZonePreviewItems($plot->plantZones),
        ];
    }

    private function buildZonePreviewItems(Collection $zones): array
    {
        $fallbackZones = $this->buildFallbackZoneGeometries($zones->count());

        return $zones
            ->values()
            ->map(function ($zone, $index) use ($fallbackZones) {
                $geometry = NormalizedGeometry::isValid($zone->geometry)
                    ? $zone->geometry
                    : $fallbackZones[$index];
                $bounds = $this->getBounds($geometry['points']);
                $color = self::ZONE_COLORS[$index % count(self::ZONE_COLORS)];

                return [
                    'name' => $zone->name,
                    'points' => $this->toSvgPoints($geometry['points']),
                    'fill' => $color['fill'],
                    'stroke' => $color['stroke'],
                    'label' => $this->shortLabel($zone->name ?? 'Zone'),
                    'show_label' => ($bounds['width'] >= 0.16 && $bounds['height'] >= 0.09),
                    'label_x' => round(($bounds['left'] + ($bounds['width'] / 2)) * 100, 2),
                    'label_y' => round(($bounds['top'] + ($bounds['height'] / 2)) * 100, 2),
                ];
            })
            ->all();
    }

    private function buildFallbackZoneGeometries(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $columns = max(1, (int) ceil(sqrt($count)));
        $rows = max(1, (int) ceil($count / $columns));
        $gap = 0.03;
        $usableWidth = 0.88 - ($gap * ($columns - 1));
        $usableHeight = 0.78 - ($gap * ($rows - 1));
        $cellWidth = $usableWidth / $columns;
        $cellHeight = $usableHeight / $rows;
        $items = [];

        for ($index = 0; $index < $count; $index += 1) {
            $column = $index % $columns;
            $row = (int) floor($index / $columns);
            $left = 0.06 + ($column * ($cellWidth + $gap));
            $top = 0.12 + ($row * ($cellHeight + $gap));
            $right = $left + $cellWidth;
            $bottom = $top + $cellHeight;

            $items[] = [
                'points' => [
                    ['x' => round($left, 4), 'y' => round($top, 4)],
                    ['x' => round($right, 4), 'y' => round($top, 4)],
                    ['x' => round($right, 4), 'y' => round($bottom, 4)],
                    ['x' => round($left, 4), 'y' => round($bottom, 4)],
                ],
            ];
        }

        return $items;
    }

    private function toSvgPoints(array $points): string
    {
        return collect($points)
            ->map(function (array $point) {
                return round(((float) $point['x']) * 100, 2).','.round(((float) $point['y']) * 100, 2);
            })
            ->implode(' ');
    }

    private function getBounds(array $points): array
    {
        $xs = array_map(static fn (array $point) => (float) $point['x'], $points);
        $ys = array_map(static fn (array $point) => (float) $point['y'], $points);
        $left = min($xs);
        $right = max($xs);
        $top = min($ys);
        $bottom = max($ys);

        return [
            'left' => $left,
            'top' => $top,
            'width' => $right - $left,
            'height' => $bottom - $top,
        ];
    }

    private function shortLabel(string $name): string
    {
        $name = trim($name);

        if (mb_strlen($name) <= 18) {
            return $name;
        }

        return mb_substr($name, 0, 17).'...';
    }
}
