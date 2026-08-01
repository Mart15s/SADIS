<?php

namespace App\Support;

use App\Models\PlantZone;

final class ZoneColor
{
    public const PALETTE = [
        '#4F7A5A',
        '#A06B3B',
        '#3F7C78',
        '#7A659A',
        '#9A5C54',
        '#667A3F',
        '#4B6F8A',
        '#8A7048',
    ];

    public static function normalize(?string $color): ?string
    {
        if ($color === null || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return null;
        }

        return strtoupper($color);
    }

    public static function suggestForPlot(?int $plotId, ?int $exceptZoneId = null): string
    {
        if (! $plotId) {
            return self::PALETTE[0];
        }

        $used = PlantZone::query()
            ->where(fn ($query) => $query->where('plot_id', $plotId)->orWhere('fk_plot_id', $plotId))
            ->when($exceptZoneId, fn ($query) => $query->whereKeyNot($exceptZoneId))
            ->whereNotNull('color_hex')
            ->pluck('color_hex')
            ->map(fn ($color) => self::normalize((string) $color))
            ->filter()
            ->values()
            ->all();

        if ($used === []) {
            return self::PALETTE[0];
        }

        return collect(self::PALETTE)
            ->map(fn (string $candidate, int $index): array => [
                'color' => $candidate,
                'index' => $index,
                'distance' => min(array_map(
                    fn (string $existing): int => self::distanceSquared($candidate, $existing),
                    $used,
                )),
            ])
            ->sortByDesc('distance')
            ->sortBy(fn (array $entry): string => sprintf('%010d-%03d', 999999999 - $entry['distance'], $entry['index']))
            ->first()['color'];
    }

    private static function distanceSquared(string $left, string $right): int
    {
        [$lr, $lg, $lb] = self::rgb($left);
        [$rr, $rg, $rb] = self::rgb($right);

        return (($lr - $rr) ** 2) + (($lg - $rg) ** 2) + (($lb - $rb) ** 2);
    }

    private static function rgb(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];
    }
}
