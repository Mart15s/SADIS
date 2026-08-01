export const ZONE_PALETTE = [
  '#4F7A5A',
  '#A06B3B',
  '#3F7C78',
  '#7A659A',
  '#9A5C54',
  '#667A3F',
  '#4B6F8A',
  '#8A7048',
]

export const PLOT_ZOOM_TIERS = Object.freeze({
  desktop: { distant: 18, close: 40 },
  mobile: { distant: 28, close: 55 },
})

export function normalizeZoneColor(value) {
  const normalized = String(value ?? '').trim().toUpperCase()
  return /^#[0-9A-F]{6}$/.test(normalized) ? normalized : null
}

function rgb(hex) {
  const color = normalizeZoneColor(hex) ?? ZONE_PALETTE[0]
  return [1, 3, 5].map((start) => Number.parseInt(color.slice(start, start + 2), 16))
}

function distanceSquared(left, right) {
  const a = rgb(left)
  const b = rgb(right)
  return a.reduce((total, value, index) => total + ((value - b[index]) ** 2), 0)
}

export function suggestZoneColor(usedColors = []) {
  const used = usedColors.map(normalizeZoneColor).filter(Boolean)
  if (!used.length) return ZONE_PALETTE[0]

  return ZONE_PALETTE
    .map((color, index) => ({
      color,
      index,
      distance: Math.min(...used.map((usedColor) => distanceSquared(color, usedColor))),
    }))
    .sort((left, right) => right.distance - left.distance || left.index - right.index)[0].color
}

export function darkenHex(hex, factor = 0.72) {
  const [red, green, blue] = rgb(hex).map((channel) => Math.round(channel * factor))
  return `#${[red, green, blue].map((channel) => channel.toString(16).padStart(2, '0')).join('')}`.toUpperCase()
}

export function hexToRgba(hex, alpha = 1) {
  const [red, green, blue] = rgb(hex)
  return `rgba(${red}, ${green}, ${blue}, ${alpha})`
}

export function zoneVisualColors(zone, index = 0) {
  const base = normalizeZoneColor(zone?.color_hex) ?? ZONE_PALETTE[index % ZONE_PALETTE.length]
  return { fill: base, stroke: darkenHex(base), foreground: getContrastColor(base) }
}

export function getContrastColor(hex) {
  const [red, green, blue] = rgb(hex).map((channel) => {
    const value = channel / 255
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
  })
  return ((0.2126 * red) + (0.7152 * green) + (0.0722 * blue)) > 0.42 ? '#1D2A1F' : '#FFFFFF'
}

export function getZoomTier(scale, mobile = false) {
  const thresholds = mobile ? PLOT_ZOOM_TIERS.mobile : PLOT_ZOOM_TIERS.desktop
  if (scale < thresholds.distant) return 'distant'
  if (scale < thresholds.close) return 'medium'
  return 'close'
}

export function getPlantStatusSemantic(plant) {
  if (plant?.disease || plant?.condition === 'diseased') {
    return { key: 'critical', label: 'Critical status or disease', symbol: '!' }
  }
  if (plant?.condition === 'dried' || plant?.harvest_date) {
    return { key: 'inactive', label: 'Inactive or harvested', symbol: '-' }
  }
  if (['flowering', 'mature'].includes(plant?.condition)) {
    return { key: 'attention', label: 'Needs attention or upcoming work', symbol: '*' }
  }
  if (['growing', 'germinating', 'planted', 'regenerating'].includes(plant?.condition)) {
    return { key: 'healthy', label: 'Growing normally', symbol: 'OK' }
  }
  return { key: 'neutral', label: 'Status not specified', symbol: '?' }
}

export function plantingYear(plant) {
  return plant?.plant_date ? String(plant.plant_date).slice(0, 4) : ''
}

export function plantingSeason(plant) {
  if (plant?.season) return String(plant.season).toLowerCase()
  const month = Number(String(plant?.plant_date ?? '').slice(5, 7))
  if ([12, 1, 2].includes(month)) return 'winter'
  if ([3, 4, 5].includes(month)) return 'spring'
  if ([6, 7, 8].includes(month)) return 'summer'
  if ([9, 10, 11].includes(month)) return 'autumn'
  return ''
}
