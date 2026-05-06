export const SOIL_TYPES = ['clay', 'peaty', 'rocky', 'sandy']
export const PLANT_TYPES = ['berry', 'cereal', 'flower', 'forage', 'fruit', 'herb', 'legume', 'oilseed', 'shrub', 'tree', 'vegetable']
export const CONDITION_TYPES = [
  'diseased',
  'dried',
  'flowering',
  'germinating',
  'growing',
  'mature',
  'planted',
  'regenerating',
]
export const INVENTORY_TYPES = ['material', 'tool']
export const INVENTORY_UNITS = ['unit', 'g', 'kg', 'ml', 'l', 'bag', 'pack', 'm3']
export const MATERIAL_UNITS = INVENTORY_UNITS
export const TOOL_UNITS = ['unit']
export const INVENTORY_UNIT_LABELS = {
  unit: 'vnt.',
  g: 'g',
  kg: 'kg',
  ml: 'ml',
  l: 'l',
  bag: 'bag',
  pack: 'pack',
  m3: 'm3',
}
export const ACCESS_ROLES = ['viewer', 'editor']
export const USER_ROLES = ['owner', 'admin']

const DISPLAY_LOCALE = 'lt-LT'
const NUMBER_LOCALE = 'en-US'
const DATE_FORMATTER = new Intl.DateTimeFormat(DISPLAY_LOCALE, {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})
const DATE_TIME_FORMATTER = new Intl.DateTimeFormat(DISPLAY_LOCALE, {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
})

export function formatDate(value, options = {}) {
  if (!value) {
    return 'Not set'
  }

  return Object.keys(options).length
    ? new Intl.DateTimeFormat(DISPLAY_LOCALE, options).format(new Date(value))
    : DATE_FORMATTER.format(new Date(value))
}

export function formatDateTime(value) {
  if (!value) {
    return 'Not set'
  }

  return DATE_TIME_FORMATTER.format(new Date(value))
}

export function safeNumber(value, digits = 0) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return '0'
  }

  return Number(value).toFixed(digits)
}

export function hasDisplayValue(value) {
  return value !== null && value !== undefined && value !== ''
}

export function formatDisplayValue(value, fallback = 'Not set') {
  return hasDisplayValue(value) ? value : fallback
}

export function formatCompactNumber(value, digits = 0, fallback = 'Not set') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return new Intl.NumberFormat(NUMBER_LOCALE, {
    maximumFractionDigits: digits,
  }).format(Number(value))
}

export function formatDayCount(value, fallback = 'Not set') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  const numeric = Number(value)
  const unit = Math.abs(numeric) === 1 ? 'day' : 'days'
  return `${formatCompactNumber(numeric, Number.isInteger(numeric) ? 0 : 1)} ${unit}`
}

export function formatTemperatureC(value, digits = 1, fallback = 'Not set') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return `${formatCompactNumber(value, digits)} °C`
}

export function formatSquareMetersValue(value, digits = 2, fallback = 'Not set') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return `${formatCompactNumber(value, digits)} m²`
}

export function formatNumberWithUnit(value, unit, digits = 0, fallback = 'Not set') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return `${formatCompactNumber(value, digits)} ${unit}`.trim()
}

export function formatInventoryUnit(unit) {
  return INVENTORY_UNIT_LABELS[unit] ?? unit ?? 'vnt.'
}

export function formatMonthYear(value) {
  if (!value) {
    return 'Not set'
  }

  return new Intl.DateTimeFormat('en-GB', {
    month: 'long',
    year: 'numeric',
  }).format(new Date(value))
}
