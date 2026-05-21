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
export const INVENTORY_UNITS = ['unit', 'g', 'kg', 'ml', 'l', 'm', 'cm', 'bag', 'pack', 'm3']
export const MATERIAL_UNITS = INVENTORY_UNITS
export const TOOL_UNITS = ['unit']
export const INVENTORY_UNIT_LABELS = {
  unit: 'vnt.',
  units: 'vnt.',
  pcs: 'vnt.',
  piece: 'vnt.',
  pieces: 'vnt.',
  g: 'g',
  gram: 'g',
  grams: 'g',
  kg: 'kg',
  kilogram: 'kg',
  kilograms: 'kg',
  ml: 'ml',
  milliliter: 'ml',
  milliliters: 'ml',
  millilitre: 'ml',
  millilitres: 'ml',
  l: 'l',
  liter: 'l',
  liters: 'l',
  litre: 'l',
  litres: 'l',
  m: 'm',
  meter: 'm',
  meters: 'm',
  metre: 'm',
  metres: 'm',
  cm: 'cm',
  centimeter: 'cm',
  centimeters: 'cm',
  centimetre: 'cm',
  centimetres: 'cm',
  bag: 'maiš.',
  bags: 'maiš.',
  pack: 'pak.',
  packs: 'pak.',
  package: 'pak.',
  packages: 'pak.',
  m3: 'm³',
}
export const ACCESS_ROLES = ['viewer', 'editor']
export const USER_ROLES = ['owner', 'admin']

const DISPLAY_LOCALE = 'lt-LT'
const NUMBER_LOCALE = 'lt-LT'
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
    return 'Nenurodyta'
  }

  return Object.keys(options).length
    ? new Intl.DateTimeFormat(DISPLAY_LOCALE, options).format(new Date(value))
    : DATE_FORMATTER.format(new Date(value))
}

export function formatDateTime(value) {
  if (!value) {
    return 'Nenurodyta'
  }

  return DATE_TIME_FORMATTER.format(new Date(value))
}

export function safeNumber(value, digits = 0) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return '0'
  }

  return formatCompactNumber(value, digits, '0')
}

export function hasDisplayValue(value) {
  return value !== null && value !== undefined && value !== ''
}

export function formatDisplayValue(value, fallback = 'Nenurodyta') {
  return hasDisplayValue(value) ? value : fallback
}

export function formatCompactNumber(value, digits = 0, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return new Intl.NumberFormat(NUMBER_LOCALE, {
    maximumFractionDigits: digits,
  }).format(Number(value))
}

export function formatDayCount(value, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  const numeric = Number(value)
  const rounded = formatCompactNumber(numeric, Number.isInteger(numeric) ? 0 : 1)
  const absolute = Math.abs(numeric)
  const unit = absolute % 10 === 1 && absolute % 100 !== 11 ? 'diena' : 'dienos'
  return `${rounded} ${unit}`
}

export function formatTemperatureC(value, digits = 1, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return `${formatCompactNumber(value, digits)} °C`
}

export function formatArea(valueInSquareMeters, digits = 1, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(valueInSquareMeters) || Number.isNaN(Number(valueInSquareMeters))) {
    return fallback
  }

  const area = Number(valueInSquareMeters)
  const squareMeters = `${formatCompactNumber(area, Number.isInteger(area) ? 0 : digits)} m²`

  if (Math.abs(area) >= 10000) {
    return `${squareMeters} (${formatCompactNumber(area / 100, 1)} a / ${formatCompactNumber(area / 10000, 2)} ha)`
  }

  if (Math.abs(area) >= 100) {
    return `${squareMeters} (${formatCompactNumber(area / 100, 1)} a)`
  }

  return squareMeters
}

export function formatLength(valueInMeters, digits = 1, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(valueInMeters) || Number.isNaN(Number(valueInMeters))) {
    return fallback
  }

  return `${formatCompactNumber(valueInMeters, digits)} m`
}

export function formatSquareMetersValue(value, digits = 2, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return formatArea(value, digits, fallback)
}

export function formatNumberWithUnit(value, unit, digits = 0, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return `${formatCompactNumber(value, digits)} ${unit}`.trim()
}

export function formatInventoryUnit(unit) {
  return INVENTORY_UNIT_LABELS[String(unit ?? '').toLowerCase()] ?? unit ?? 'vnt.'
}

export function formatQuantity(value, unit, digits = 0, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(value) || Number.isNaN(Number(value))) {
    return fallback
  }

  return `${formatCompactNumber(value, digits)} ${formatInventoryUnit(unit)}`.trim()
}

export const SOIL_TYPE_LABELS = {
  clay: 'Molis',
  peaty: 'Durpinga',
  rocky: 'Akmeninga',
  sandy: 'Smėlinga',
}

export const PLANT_TYPE_LABELS = {
  berry: 'Uoga',
  cereal: 'Javai',
  flower: 'Gėlė',
  forage: 'Pašarinis augalas',
  fruit: 'Vaisius',
  herb: 'Prieskoninis augalas',
  legume: 'Ankštinis augalas',
  oilseed: 'Aliejinis augalas',
  shrub: 'Krūmas',
  tree: 'Medis',
  vegetable: 'Daržovė',
}

export const PLANT_CONDITION_LABELS = {
  diseased: 'Sergantis',
  dried: 'Išdžiūvęs',
  flowering: 'Žydi',
  germinating: 'Dygsta',
  growing: 'Auga',
  healthy: 'Sveikas',
  mature: 'Subrendęs',
  planted: 'Pasodintas',
  regenerating: 'Atsigauna',
  seedling: 'Daigas',
}

export const TASK_STATUS_LABELS = {
  pending: 'Laukiama',
  planned: 'Suplanuota',
  completed: 'Atlikta',
  rejected: 'Atmesta',
}

export const TASK_TYPE_LABELS = {
  watering: 'Laistymas',
  fertilizing: 'Tręšimas',
  pest_check: 'Kenkėjų patikra',
  harvest: 'Derliaus nuėmimas',
  buy: 'Pirkimas',
  frost_protection: 'Apsauga nuo šalnos',
  heat_extra_watering: 'Papildomas laistymas per karštį',
  wind_protection: 'Apsauga nuo vėjo',
  lifecycle_review: 'Augalo būklės peržiūra',
}

export const PRIORITY_LABELS = {
  low: 'Žemas',
  medium: 'Vidutinis',
  high: 'Aukštas',
}

export const INVENTORY_TYPE_LABELS = {
  material: 'Medžiaga',
  tool: 'Įrankis',
}

export const ACCESS_ROLE_LABELS = {
  viewer: 'Peržiūros teisė',
  editor: 'Redagavimo teisė',
  owner: 'Savininkas',
  admin: 'Administratorius',
}

export const USER_ROLE_LABELS = {
  owner: 'Savininkas',
  admin: 'Administratorius',
}

export const ROTATION_STATUS_LABELS = {
  assigned: 'Priskirta',
  blocked: 'Blokuota',
  generated: 'Sugeneruota',
  manual_override: 'Rankinis įrašas',
  ready: 'Paruošta',
  rejected: 'Atmesta',
  stays: 'Lieka vietoje',
  unresolved: 'Neišspręsta',
}

export const SNAPSHOT_TYPE_LABELS = {
  created_plot_version: 'Sukurta sklypo versija',
  initial_plot_version: 'Sukurta pradinė sklypo versija',
  manual: 'Rankinis įrašas',
  saved_layout_update: 'Išsaugotas išdėstymo pakeitimas',
  update: 'Išsaugotas pakeitimas',
}

const SNAPSHOT_TEXT_TRANSLATIONS = {
  'Saved layout update': 'Išsaugotas išdėstymo pakeitimas',
  'Layout updated, 3 zone addeds.': 'Išdėstymas atnaujintas, pridėtos 3 zonos.',
  'Created plot version': 'Sukurta sklypo versija',
  'Initial plot version was created.': 'Sukurta pradinė sklypo versija.',
}

function formatMappedValue(value, labels, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(value)) {
    return fallback
  }

  return labels[String(value).toLowerCase()] ?? value
}

export function formatSoilType(type) {
  return formatMappedValue(type, SOIL_TYPE_LABELS)
}

export function formatPlantType(type) {
  return formatMappedValue(type, PLANT_TYPE_LABELS)
}

export function formatPlantCondition(condition) {
  return formatMappedValue(condition, PLANT_CONDITION_LABELS)
}

export function formatLifecycleStage(stage) {
  return formatPlantCondition(stage)
}

export function formatTaskStatus(status) {
  return formatMappedValue(status, TASK_STATUS_LABELS)
}

export function formatTaskType(type) {
  return formatMappedValue(type, TASK_TYPE_LABELS)
}

export function formatPriority(priority) {
  return formatMappedValue(priority, PRIORITY_LABELS)
}

export function formatInventoryType(type) {
  return formatMappedValue(type, INVENTORY_TYPE_LABELS)
}

export function formatAccessRole(role) {
  return formatMappedValue(role, ACCESS_ROLE_LABELS)
}

export function formatUserRole(role) {
  return formatMappedValue(role, USER_ROLE_LABELS)
}

export function formatRotationStatus(status) {
  return formatMappedValue(status, ROTATION_STATUS_LABELS)
}

export function formatSnapshotType(type) {
  return formatMappedValue(type, SNAPSHOT_TYPE_LABELS)
}

export function formatSnapshotText(value, fallback = 'Nenurodyta') {
  if (!hasDisplayValue(value)) {
    return fallback
  }

  return SNAPSHOT_TEXT_TRANSLATIONS[value] ?? formatSnapshotType(value)
}

export function formatMonthYear(value) {
  if (!value) {
    return 'Nenurodyta'
  }

  return new Intl.DateTimeFormat(DISPLAY_LOCALE, {
    month: 'long',
    year: 'numeric',
  }).format(new Date(value))
}
