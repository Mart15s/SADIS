const NAME_VISUALS = [
  ['blueberr', 'berry'],
  ['mėlyn', 'berry'],
  ['strawberr', 'berry'],
  ['brašk', 'berry'],
  ['mint', 'herb'],
  ['mėt', 'herb'],
  ['basil', 'herb'],
  ['bazilik', 'herb'],
  ['tomato', 'fruit'],
  ['pomidor', 'fruit'],
  ['apple', 'tree'],
  ['obuol', 'tree'],
  ['carrot', 'root'],
  ['mork', 'root'],
  ['bean', 'legume'],
  ['pupel', 'legume'],
  ['pea', 'legume'],
  ['žirn', 'legume'],
  ['lettuce', 'leafy'],
  ['salot', 'leafy'],
  ['wheat', 'grain'],
  ['kvieč', 'grain'],
  ['rose', 'flower'],
  ['gėl', 'flower'],
]

const TYPE_VISUALS = {
  tree: 'tree', fruit: 'fruit', berry: 'berry', herb: 'herb', leafy: 'leafy',
  vegetable: 'leafy', root: 'root', legume: 'legume', grain: 'grain', flower: 'flower', shrub: 'shrub',
}

export function plantMonogram(name) {
  const words = String(name ?? '').trim().split(/\s+/).filter(Boolean)
  return (words.length > 1 ? `${words[0][0]}${words[1][0]}` : (words[0] ?? '?').slice(0, 2)).toLocaleUpperCase('lt')
}

export function plantVisual(plant = {}) {
  const explicit = plant.icon_key ?? plant.icon ?? plant.image ?? plant.image_url
  if (explicit) return { key: 'explicit', explicit, monogram: plantMonogram(plant.name) }

  const type = String(plant.category ?? plant.plant_type ?? plant.type ?? '').toLowerCase()
  if (TYPE_VISUALS[type]) return { key: TYPE_VISUALS[type], monogram: plantMonogram(plant.name) }

  const normalizedName = String(plant.name ?? '').toLocaleLowerCase('lt')
  const matched = NAME_VISUALS.find(([term]) => normalizedName.includes(term))
  return { key: matched?.[1] ?? 'generic', monogram: plantMonogram(plant.name) }
}

export function markerPosition(plant) {
  const x = Number(plant?.marker_position_x)
  const y = Number(plant?.marker_position_y)
  return Number.isFinite(x) && Number.isFinite(y) && x >= 0 && x <= 1 && y >= 0 && y <= 1 ? { x, y } : null
}
