export function createPlotPlanFixture(zoneCount = 100, plantCount = 300) {
  const zones = Array.from({ length: zoneCount }, (_, index) => ({
    id: index + 1,
    name: `Zona ${index + 1}`,
    color_hex: ['#4F7A5A', '#A06B3B', '#3F7C78', '#7A659A'][index % 4],
    zone_size: 12,
  }))
  const layouts = Object.fromEntries(zones.map((zone, index) => {
    const x = (index % 10) * 8
    const y = Math.floor(index / 10) * 4
    return [String(zone.id), {
      kind: 'polygon',
      points: [{ x, y }, { x: x + 7, y }, { x: x + 7, y: y + 3 }, { x, y: y + 3 }],
    }]
  }))
  const plants = Array.from({ length: plantCount }, (_, index) => ({
    id: index + 1,
    name: `Augalas ${index + 1}`,
    condition: index % 17 === 0 ? 'diseased' : 'growing',
    fk_plant_zone_id: (index % zoneCount) + 1,
    quantity: index + 1,
    plant_date: '2026-04-10',
  }))

  return { zones, layouts, plants }
}
