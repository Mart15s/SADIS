<!DOCTYPE html>
<html lang="lt">
<head>
    <meta charset="UTF-8">
    <title>Sklypo ataskaita</title>
    <style>
        body {
            color: #1f2933;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.45;
            margin: 28px;
        }
        h1, h2, h3 {
            color: #12344d;
            margin: 0 0 10px;
        }
        h1 {
            font-size: 24px;
        }
        h2 {
            border-bottom: 1px solid #d9e2ec;
            font-size: 16px;
            margin-top: 26px;
            padding-bottom: 6px;
        }
        h3 {
            font-size: 13px;
            margin-top: 16px;
        }
        p {
            margin: 0 0 8px;
        }
        .muted {
            color: #52606d;
        }
        .meta-table,
        .data-table {
            border-collapse: collapse;
            margin-top: 10px;
            width: 100%;
        }
        .meta-table td,
        .data-table th,
        .data-table td {
            border: 1px solid #d9e2ec;
            padding: 8px;
            vertical-align: top;
        }
        .data-table th {
            background: #f0f4f8;
            font-size: 11px;
            text-align: left;
        }
        .pill {
            background: #f0f4f8;
            border: 1px solid #d9e2ec;
            border-radius: 10px;
            display: inline-block;
            margin: 2px 6px 2px 0;
            padding: 3px 8px;
        }
        .empty {
            background: #f8fafc;
            border: 1px dashed #bcccdc;
            color: #52606d;
            padding: 10px;
        }
        .two-column {
            width: 100%;
        }
        .two-column td {
            vertical-align: top;
            width: 50%;
        }
        .plan-preview {
            border: 1px solid #d9e2ec;
            border-radius: 14px;
            margin-top: 12px;
            overflow: hidden;
            padding: 14px;
        }
        .plan-meta {
            color: #52606d;
            font-size: 11px;
            margin-bottom: 10px;
        }
        .plan-svg {
            display: block;
            height: auto;
            width: 100%;
        }
        .plan-outline {
            fill: rgba(255, 250, 242, 0.92);
            stroke: #47633b;
            stroke-width: 1.8;
        }
        .plan-zone {
            stroke-width: 1.2;
        }
        .plan-label {
            fill: #24311f;
            font-size: 4.2px;
            font-weight: bold;
            text-anchor: middle;
            dominant-baseline: middle;
        }
    </style>
</head>
<body>
    <h1>SAD System sklypo ataskaita</h1>
    <p class="muted">
        Sugeneruota: {{ $generated_at->toIso8601String() }}
    </p>
    <p class="muted">
        Sklypas: {{ $plot->name }}
    </p>

    <h2>Sklypo apzvalga</h2>
    <table class="meta-table">
        <tr>
            <td><strong>Pavadinimas</strong><br>{{ $plot->name }}</td>
            <td><strong>Miestas</strong><br>{{ $plot->city ?? 'Nenurodyta' }}</td>
        </tr>
        <tr>
            <td><strong>Dydis</strong><br>{{ $plot->plot_size !== null ? number_format((float) $plot->plot_size, 2, '.', '') . ' m²' : 'Nenurodyta' }}</td>
            <td><strong>Sukurta</strong><br>{{ $plot->creation_date?->toDateString() ?? 'Nenurodyta' }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Aprasymas</strong><br>{{ $plot->description ?: 'Aprasymas nepateiktas.' }}</td>
        </tr>
    </table>

    <h2>Vizualus planas</h2>
    <div class="plan-preview" data-plan-source="{{ $plan_preview['source'] }}">
        <div class="plan-meta">
            {{ $plan_preview['source'] === 'geometry' ? 'Naudojama issaugota geometrija.' : 'Naudojamas numatytasis atsarginis isdestymas.' }}
        </div>
        <svg class="plan-svg" viewBox="0 0 100 100" aria-label="Sklypo plano perziura">
            <polygon class="plan-outline" points="{{ $plan_preview['plot']['points'] }}" />
            @foreach ($plan_preview['zones'] as $zonePreview)
                <polygon
                    class="plan-zone"
                    points="{{ $zonePreview['points'] }}"
                    fill="{{ $zonePreview['fill'] }}"
                    fill-opacity="0.88"
                    stroke="{{ $zonePreview['stroke'] }}"
                />
                @if ($zonePreview['show_label'])
                    <text
                        class="plan-label"
                        x="{{ $zonePreview['label_x'] }}"
                        y="{{ $zonePreview['label_y'] }}"
                    >{{ $zonePreview['label'] }}</text>
                @endif
            @endforeach
        </svg>
    </div>

    <h2>Bazine analitika</h2>
    <table class="meta-table">
        <tr>
            <td><strong>Zonos</strong><br>{{ $analytics['summary']['total_zones'] }}</td>
            <td><strong>Augalai</strong><br>{{ $analytics['summary']['total_plants'] }}</td>
            <td><strong>Aktyvus augalai</strong><br>{{ $analytics['summary']['active_plants_count'] }}</td>
        </tr>
        <tr>
            <td><strong>Sergantys augalai</strong><br>{{ $analytics['summary']['diseased_plants_count'] }}</td>
            <td><strong>Bendrinti naudotojai</strong><br>{{ $analytics['summary']['shared_users_count'] }}</td>
            <td><strong>Uzdociu ivykdymas</strong><br>{{ number_format($task_metrics['completion_ratio'] * 100, 2, '.', '') }}%</td>
        </tr>
    </table>

    <h2>Zonu apzvalga</h2>
    @if ($plot->plantZones->isEmpty())
        <div class="empty">Sklypui dar nepriskirta zonu.</div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Pavadinimas</th>
                    <th>Dydis</th>
                    <th>Dirvozemis</th>
                    <th>Rotacijos etapas</th>
                    <th>Augalu skaicius</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($plot->plantZones as $zone)
                    <tr>
                        <td>{{ $zone->name }}</td>
                        <td>{{ $zone->zone_size !== null ? number_format((float) $zone->zone_size, 2, '.', '') . ' m²' : 'Nenurodyta' }}</td>
                        <td>{{ $zone->soil_type?->value ?? 'Nenurodyta' }}</td>
                        <td>{{ $zone->rotation_stage ?? 'Nenurodyta' }}</td>
                        <td>{{ $zone->plants_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Augalu apzvalga</h2>
    @if ($plot->plants->isEmpty())
        <div class="empty">Sklype dar nera augalu.</div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Pavadinimas</th>
                    <th>Zona</th>
                    <th>Pasodinimo data</th>
                    <th>Bukle</th>
                    <th>Liga</th>
                    <th>Prieziuros profilis</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($plot->plants as $plant)
                    <tr>
                        <td>{{ $plant->name }}</td>
                        <td>{{ $plant->plantZone?->name ?? 'Nenurodyta' }}</td>
                        <td>{{ $plant->plant_date?->toDateString() ?? 'Nenurodyta' }}</td>
                        <td>{{ $plant->condition?->value ?? 'Nenurodyta' }}</td>
                        <td>{{ $plant->disease ?: 'Nera' }}</td>
                            <td>{{ $plant->catalogPlant?->plantCare?->plant_name ? $plant->catalogPlant->plantCare->plant_name . ' #' . $plant->catalogPlant->plantCare->id : 'Nepriskirta' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Kalendoriaus ir uzduociu santrauka</h2>
    <table class="meta-table">
        <tr>
            <td><strong>Kalendoriai</strong><br>{{ $task_metrics['total_calendars'] }}</td>
            <td><strong>Visos uzduotys</strong><br>{{ $task_metrics['total_tasks'] }}</td>
            <td><strong>Laukiancios</strong><br>{{ $task_metrics['pending_tasks'] }}</td>
        </tr>
        <tr>
            <td><strong>Ivykdytos</strong><br>{{ $task_metrics['completed_tasks'] }}</td>
            <td><strong>Atsauktos</strong><br>{{ $task_metrics['cancelled_tasks'] }}</td>
            <td><strong>Derliaus uzduotys</strong><br>{{ data_get($analytics, 'sections.harvest.total_harvest_tasks', 0) }}</td>
        </tr>
    </table>

    <h3>Naujausi kalendoriai</h3>
    @if ($recent_calendars->isEmpty())
        <div class="empty">Sklypui dar nesugeneruota kalendoriu.</div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Sukurta</th>
                    <th>Pradzia</th>
                    <th>Pabaiga</th>
                    <th>Uzduociu kiekis</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recent_calendars as $calendar)
                    <tr>
                        <td>{{ $calendar->id }}</td>
                        <td>{{ $calendar->creation_date?->toIso8601String() ?? 'Nenurodyta' }}</td>
                        <td>{{ $calendar->start_date?->toDateString() ?? 'Nenurodyta' }}</td>
                        <td>{{ $calendar->end_date?->toDateString() ?? 'Nenurodyta' }}</td>
                        <td>{{ $calendar->tasks->count() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>Naujausios uzduotys</h3>
    @if ($recent_tasks->isEmpty())
        <div class="empty">Uzduociu nerasta.</div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Pavadinimas</th>
                    <th>Tipas</th>
                    <th>Busena</th>
                    <th>Augalas</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recent_tasks as $task)
                    <tr>
                        <td>{{ $task->date?->toDateString() ?? 'Nenurodyta' }}</td>
                        <td>{{ $task->name }}</td>
                        <td>{{ $task->type ?? 'Nenurodyta' }}</td>
                        <td>{{ $task->status }}</td>
                        <td>{{ $task->plant?->name ?? 'Netaikoma' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Istorijos santrauka</h2>
    <table class="two-column">
        <tr>
            <td>
                <h3>Rotacijos istorija</h3>
                @if ($recent_rotation_history->isEmpty())
                    <div class="empty">Rotacijos istorijos irasu nera.</div>
                @else
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nuo</th>
                                <th>Iki</th>
                                <th>Zona</th>
                                <th>Augalas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recent_rotation_history as $rotation)
                                <tr>
                                    <td>{{ $rotation->from_date?->toDateString() ?? 'Nenurodyta' }}</td>
                                    <td>{{ $rotation->to_date?->toDateString() ?? 'Nenurodyta' }}</td>
                                    <td>{{ $rotation->plantZone?->name ?? 'Nenurodyta' }}</td>
                                    <td>{{ $rotation->plant?->name ?? 'Nenurodyta' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </td>
            <td>
                <h3>Augalu bukles istorija</h3>
                @if ($recent_condition_history->isEmpty())
                    <div class="empty">Bukles istorijos irasu nera.</div>
                @else
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Matavimas</th>
                                <th>Augalas</th>
                                <th>Bukle</th>
                                <th>Pastabos</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recent_condition_history as $history)
                                <tr>
                                    <td>{{ $history->measured_at?->toIso8601String() ?? 'Nenurodyta' }}</td>
                                    <td>{{ $history->plant?->name ?? 'Nenurodyta' }}</td>
                                    <td>{{ $history->condition?->value ?? 'Nenurodyta' }}</td>
                                    <td>{{ $history->notes ?: 'Pastabu nera' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </td>
        </tr>
    </table>

    <h2>Detalioji analitika</h2>
    <h3>Bukliu pasiskirstymas</h3>
    @foreach (data_get($analytics, 'sections.plant_condition.counts_by_condition', []) as $condition => $count)
        <span class="pill">{{ $condition }}: {{ $count }}</span>
    @endforeach

    <h3>Rotacijos dalyvavimas pagal zonas</h3>
    @if (empty(data_get($analytics, 'sections.planning.rotation_history.zone_participation_counts', [])))
        <div class="empty">Rotacijos dalyvavimo duomenu nera.</div>
    @else
        @foreach (data_get($analytics, 'sections.planning.rotation_history.zone_participation_counts', []) as $zoneMetric)
            <span class="pill">
                {{ $zoneMetric['zone_name'] ?? 'Nezinoma zona' }}: {{ $zoneMetric['records_count'] }}
            </span>
        @endforeach
    @endif

    <h3>Derliaus santrauka</h3>
    <table class="meta-table">
        <tr>
            <td><strong>Visos derliaus uzduotys</strong><br>{{ data_get($analytics, 'sections.harvest.total_harvest_tasks', 0) }}</td>
            <td><strong>Ivykdytos derliaus uzduotys</strong><br>{{ data_get($analytics, 'sections.harvest.completed_harvest_tasks', 0) }}</td>
            <td><strong>Paskutinio derliaus data</strong><br>{{ data_get($analytics, 'sections.harvest.latest_harvest_date') ?? 'Nera' }}</td>
        </tr>
    </table>
</body>
</html>
